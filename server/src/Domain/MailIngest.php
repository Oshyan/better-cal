<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/**
 * Email-to-event ingest (docs/email-ingest.md): the worker polls the
 * calendar@ mailbox (invites arrive there directly or via a Gmail forward
 * filter) and turns messages into events through a three-tier ladder that
 * mirrors what Gmail itself does:
 *
 *   1. iMIP — a text/calendar part or .ics attachment. Authoritative:
 *      METHOD:REQUEST creates/updates by UID, METHOD:CANCEL cancels.
 *   2. schema.org markup — JSON-LD Event/EventReservation blocks embedded in
 *      the HTML by Eventbrite/Luma/airlines et al. Deterministic.
 *   3. LLM extraction — Gemini over the text body, gated by an eventish
 *      subject so ordinary mail never becomes calendar noise.
 *
 * Ingested events land in a local "Invitations" calendar with invite_json
 * carrying organizer/attendees/method for the detail view and RSVP.
 * mail_ingest logs every message id; nothing is processed twice.
 */
final class MailIngest
{
    public const INVITE_CALENDAR = 'Invitations';
    public const INVITE_COLOR = '#c96f8f';
    private const MAX_MESSAGES_PER_RUN = 10;
    private const LLM_SUBJECT_GATE = '/invit|confirm|ticket|registration|rsvp|booking|reservation|event/i';

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly ?LlmGateway $llm = null,
    ) {
        $this->review = new ReviewQueue($db, $events);
    }

    /** Where emailed changes to events the owner already has wait for a decision. */
    private readonly ReviewQueue $review;

    // ---- Pure helpers (unit-tested, no I/O) ----------------------------

    /**
     * Parse an iMIP payload: calendar METHOD plus per-event invite fields on
     * top of the shared Ics::parse event shape.
     *
     * @return array{method: string, events: list<array<string,mixed>>}|null
     */
    public static function parseImip(string $ics): ?array
    {
        // An invitation is one meeting, perhaps with a few exceptions. Anything
        // shaped like a whole calendar is not parsed at all (BC-10).
        if (Ics::budgetProblem($ics, Limits::get('MAIL_ICS_BYTES'), Limits::get('MAIL_ICS_EVENTS')) !== null) {
            return null;
        }
        try {
            $vcal = \Sabre\VObject\Reader::read(
                $ics,
                \Sabre\VObject\Reader::OPTION_FORGIVING | \Sabre\VObject\Reader::OPTION_IGNORE_INVALID_LINES
            );
        } catch (\Throwable) {
            return null;
        }
        $method = strtoupper(trim((string) ($vcal->METHOD ?? 'PUBLISH')));
        $base = Ics::parse($ics);
        $byUid = [];
        foreach ($base as $ev) {
            if (!empty($ev['uid'])) {
                $byUid[(string) $ev['uid']] = $ev;
            }
        }
        $events = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            // Same cleaning Ics::parse gave the key, or the two never meet.
            $uid = isset($vevent->UID) ? substr(Ics::structural(trim((string) $vevent->UID)), 0, 255) : null;
            $parsed = $uid !== null && isset($byUid[$uid]) ? $byUid[$uid] : null;
            if ($parsed === null) {
                continue;
            }
            $mailto = static fn(string $v): string => strtolower(preg_replace('/^mailto:/i', '', trim($v)) ?? '');
            $organizer = null;
            if (isset($vevent->ORGANIZER)) {
                $organizer = [
                    'email' => $mailto((string) $vevent->ORGANIZER),
                    'name' => isset($vevent->ORGANIZER['CN']) ? (string) $vevent->ORGANIZER['CN'] : null,
                ];
            }
            $attendees = [];
            foreach ($vevent->select('ATTENDEE') as $att) {
                $attendees[] = [
                    'email' => $mailto((string) $att),
                    'name' => isset($att['CN']) ? (string) $att['CN'] : null,
                    'partstat' => isset($att['PARTSTAT']) ? strtoupper((string) $att['PARTSTAT']) : 'NEEDS-ACTION',
                ];
            }
            $parsed['invite'] = [
                'method' => $method,
                'organizer' => $organizer,
                'attendees' => $attendees,
                'sequence' => isset($vevent->SEQUENCE) ? (int) ((string) $vevent->SEQUENCE) : 0,
                'myPartstat' => 'NEEDS-ACTION',
            ];
            $events[] = $parsed;
        }
        if ($events === []) {
            return null;
        }
        return ['method' => $method, 'events' => $events];
    }

    /**
     * Extract schema.org Event drafts from JSON-LD blocks in an HTML body.
     * Handles top-level objects, arrays, @graph wrappers, and *Reservation
     * types whose reservationFor is the actual Event.
     *
     * @return list<array{title:string,start:string,end:?string,location:?string,url:?string}>
     */
    public static function extractLdJsonEvents(string $html): array
    {
        $out = [];
        $m = [1 => []];
        foreach (Sanitize::htmlBlocks($html, ['script']) as $b) {
            if (preg_match('~type\s*=\s*["\']?application/ld\+json~i', $b['attrs']) === 1) {
                $m[1][] = $b['body'];
            }
        }
        if ($m[1] === []) {
            return $out;
        }
        $collect = function ($node) use (&$collect, &$out): void {
            if (!is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                foreach ($node as $item) {
                    $collect($item);
                }
                return;
            }
            if (isset($node['@graph'])) {
                $collect($node['@graph']);
            }
            $type = $node['@type'] ?? null;
            $types = is_array($type) ? $type : [$type];
            $isEvent = (bool) array_filter($types, static fn($t) => is_string($t) && str_contains($t, 'Event'));
            $isReservation = (bool) array_filter($types, static fn($t) => is_string($t) && str_contains($t, 'Reservation'));
            if ($isReservation && isset($node['reservationFor'])) {
                $collect($node['reservationFor']);
                return;
            }
            if (!$isEvent) {
                return;
            }
            $name = is_string($node['name'] ?? null) ? trim($node['name']) : '';
            $start = is_string($node['startDate'] ?? null) ? trim($node['startDate']) : '';
            if ($name === '' || $start === '') {
                return;
            }
            $location = null;
            $loc = $node['location'] ?? null;
            if (is_string($loc)) {
                $location = trim($loc);
            } elseif (is_array($loc)) {
                $parts = [];
                if (is_string($loc['name'] ?? null)) {
                    $parts[] = trim($loc['name']);
                }
                $addr = $loc['address'] ?? null;
                if (is_string($addr)) {
                    $parts[] = trim($addr);
                } elseif (is_array($addr)) {
                    foreach (['streetAddress', 'addressLocality', 'addressRegion'] as $k) {
                        if (is_string($addr[$k] ?? null) && trim($addr[$k]) !== '') {
                            $parts[] = trim($addr[$k]);
                        }
                    }
                }
                $location = $parts !== [] ? implode(', ', array_unique($parts)) : null;
            }
            $out[] = [
                'title' => $name,
                'start' => $start,
                'end' => is_string($node['endDate'] ?? null) ? trim($node['endDate']) : null,
                'location' => $location,
                'url' => is_string($node['url'] ?? null) ? trim($node['url']) : null,
            ];
        };
        foreach ($m[1] as $block) {
            $decoded = json_decode(html_entity_decode(trim($block)), true);
            if ($decoded !== null) {
                $collect($decoded);
            }
        }
        return $out;
    }

    /** Does a subject read as eventish enough to spend an LLM call on? */
    public static function llmGateAllows(string $subject): bool
    {
        return preg_match(self::LLM_SUBJECT_GATE, $subject) === 1;
    }

    /**
     * Google Calendar template links embedded in a body ("Add to Google"
     * buttons in Eventbrite/Luma mails). Deterministic tier between markup
     * and the LLM — forwards strip JSON-LD but keep these links.
     *
     * @return list<string>
     */
    public static function extractGcalLinks(string $content): array
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
        if (preg_match_all(
            '~https://(?:www\.)?calendar\.google\.com/calendar/(?:u/\d+/)?(?:r/eventedit|render)\?[^\s"\'<>\)\]]+~i',
            $content,
            $m
        ) < 1) {
            return [];
        }
        return array_values(array_unique($m[0]));
    }

    /** HTML body to readable text: drop script/style, strip tags, decode. */
    public static function flattenHtml(string $html): string
    {
        $html = Sanitize::dropScriptStyle($html);
        $html = preg_replace('~<br\s*/?>|</p>|</div>|</tr>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        return trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", $text) ?? $text) ?? $text);
    }

    /**
     * Remove a Gmail forward preamble (From/Date/Subject/To header block) so
     * the forward's own timestamp can't masquerade as the event date.
     */
    public static function stripForwardPreamble(string $text): string
    {
        $at = stripos($text, 'Forwarded message');
        if ($at === false) {
            return $text;
        }
        // The regex runs on a bounded window around the marker, not the whole
        // body: its leading dash run was quadratic on long runs of dashes (F9).
        // Byte offsets moved onto character boundaries: a window that splits a
        // multi-byte character made the /u regex fail and leave the header in.
        $start = max(0, $at - 200);
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        $end = min(strlen($text), $start + 1200);
        while ($end < strlen($text) && (ord($text[$end]) & 0xC0) === 0x80) {
            $end++;
        }
        $window = substr($text, $start, $end - $start);
        $replaced = preg_replace(
            '/-{3,}\s*Forwarded message\s*-{3,}\s*From:.{0,400}?To:\s*<?[^\s<>]+@[^\s<>]+>?/su',
            ' ',
            $window,
            1
        );
        return is_string($replaced) ? substr($text, 0, $start) . $replaced . substr($text, $start + strlen($window)) : $text;
    }

    /** Is there any date-shaped token to anchor an LLM parse on? */
    public static function hasDateEvidence(string $text): bool
    {
        return preg_match(
            '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}\b'
            . '|\b\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b'
            . '|\b\d{1,2}\/\d{1,2}\b|\b\d{4}-\d{2}-\d{2}\b|\btomorrow\b|\btonight\b/i',
            $text
        ) === 1;
    }

    // ---- Ingest --------------------------------------------------------

    /**
     * Process one fetched message (already decomposed by the transport).
     *
     * @param array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string} $msg
     * @return array{tier:?string,outcome:string,eventId:?int,error:?string}
     */
    public function ingestMessage(int $userId, array $msg, string $tz): array
    {
        if ($this->alreadyProcessed($msg['messageId'])) {
            return ['tier' => null, 'outcome' => 'skipped', 'eventId' => null, 'error' => 'duplicate'];
        }

        $result = ['tier' => 'none', 'outcome' => 'skipped', 'eventId' => null, 'error' => null];
        if (isset($msg['oversize'])) {
            // The fetcher never downloaded it (Limits::MAIL_BYTES). Logged like
            // any other message so "where did that invite go" has an answer.
            $result['error'] = 'message too large to ingest (' . number_format((int) $msg['oversize']) . ' bytes)';
        } else {
            try {
                $result = $this->runTiers($userId, $msg, $tz);
            } catch (\Throwable $e) {
                $result = ['tier' => 'none', 'outcome' => 'error', 'eventId' => null, 'error' => mb_substr($e->getMessage(), 0, 500)];
            }
        }
        $this->finish($msg, $result);
        return $result;
    }

    public const OUTCOME_STARTED = 'started';

    /**
     * Record that a message is about to be downloaded and parsed, BEFORE doing
     * either. If parsing it kills the worker (out of memory cannot be caught),
     * this row is what stops the next run from walking into the same message:
     * begin() returns false for a message that was started and never finished,
     * and the row stays in the log as 'started', which is how a poison message
     * shows up afterwards. Returns false as well for one already handled.
     *
     * @param array{messageId:string,subject:string,from:string} $envelope
     */
    public function begin(array $envelope): bool
    {
        try {
            $this->db->insert('mail_ingest', [
                'message_id' => mb_substr($envelope['messageId'], 0, 255),
                'subject' => mb_substr($envelope['subject'], 0, 500),
                'from_addr' => mb_substr($envelope['from'], 0, 255),
                'outcome' => self::OUTCOME_STARTED,
            ]);
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return false; // the unique message_id: seen before
            }
            throw $e;
        }
    }

    /** A retryable failure while fetching: forget the 'started' row so the next run tries again. */
    public function abort(string $messageId): void
    {
        $this->db->run('DELETE FROM mail_ingest WHERE message_id = ? AND outcome = ?', [mb_substr($messageId, 0, 255), self::OUTCOME_STARTED]);
    }

    /** Write the outcome over the 'started' row, or insert it when begin() was not used. */
    private function finish(array $msg, array $result): void
    {
        $id = mb_substr($msg['messageId'], 0, 255);
        $updated = $this->db->run(
            'UPDATE mail_ingest SET tier = ?, outcome = ?, event_id = ?, error = ? WHERE message_id = ? AND outcome = ?',
            [$result['tier'], $result['outcome'], $result['eventId'], $result['error'], $id, self::OUTCOME_STARTED]
        )->rowCount();
        if ($updated === 0) {
            try {
                $this->db->insert('mail_ingest', [
                    'message_id' => $id,
                    'subject' => mb_substr($msg['subject'], 0, 500),
                    'from_addr' => mb_substr($msg['from'], 0, 255),
                    'tier' => $result['tier'],
                    'outcome' => $result['outcome'],
                    'event_id' => $result['eventId'],
                    'error' => $result['error'],
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e; // a duplicate message id is fine: it is logged already
                }
            }
        }
    }

    /** @param array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string} $msg */
    private function runTiers(int $userId, array $msg, string $tz): array
    {
        // Tier 1: iMIP.
        foreach ($msg['icsParts'] as $ics) {
            $imip = self::parseImip($ics);
            if ($imip === null) {
                continue;
            }
            foreach ($imip['events'] as $ev) {
                $outcome = ActivityContext::with('mail:imip', fn() => $this->applyImipEvent($userId, $imip['method'], $ev, $tz, (string) ($msg['from'] ?? '')));
                if ($outcome !== null) {
                    return [
                        'tier' => 'imip',
                        'outcome' => $outcome[0],
                        'eventId' => $outcome[1],
                        'error' => $outcome[2] ?? null,
                    ];
                }
            }
        }

        // Tier 2: schema.org markup.
        if ($msg['html'] !== null) {
            foreach (self::extractLdJsonEvents($msg['html']) as $draft) {
                $created = ActivityContext::with('mail:markup', fn() => $this->createFromDraft($userId, $msg, $draft, 'markup', $tz));
                if ($created !== null) {
                    return ['tier' => 'markup', 'outcome' => 'created', 'eventId' => $created, 'error' => null];
                }
            }
        }

        // Tier 2.5: an embedded "Add to Google Calendar" template link.
        // Deterministic like markup, and survives Gmail forwards (which strip
        // JSON-LD but keep hrefs).
        foreach (self::extractGcalLinks(($msg['html'] ?? '') . ' ' . ($msg['text'] ?? '')) as $link) {
            $draft = GcalLink::parse($link, $tz);
            if ($draft === null) {
                continue;
            }
            $created = ActivityContext::with('mail:gcal-link', fn() => $this->createFromDraft($userId, $msg, [
                'title' => $draft['title'],
                'start' => $draft['start'],
                'end' => $draft['end'],
                'allDay' => $draft['allDay'],
                'location' => $draft['location'],
                'description' => $draft['description'],
                'url' => null,
            ], 'gcal-link', $tz));
            if ($created !== null) {
                return ['tier' => 'gcal-link', 'outcome' => 'created', 'eventId' => $created, 'error' => null];
            }
        }

        // Tier 3: LLM, subject-gated. The flattened HTML is the readable
        // version of marketing mail (the text/plain part is mostly tracking
        // links); the forward preamble is stripped so the forward's own
        // timestamp can't be mistaken for the event date, and with no
        // date-shaped token at all there is nothing trustworthy to parse.
        $llmSource = $msg['html'] !== null ? self::flattenHtml($msg['html']) : (string) $msg['text'];
        $llmSource = self::stripForwardPreamble($llmSource);
        if ($this->llm !== null && trim($llmSource) !== '' && self::llmGateAllows($msg['subject'])) {
            if (!self::hasDateEvidence($llmSource)) {
                return ['tier' => 'llm', 'outcome' => 'skipped', 'eventId' => null, 'error' => 'no date evidence in body'];
            }
            $body = mb_substr(self::stripForwardPreamble($msg['subject']) . "\n\n" . $llmSource, 0, 4000);
            $parsed = $this->llm->parseEvent($body, Time::nowUtc(), $tz);
            if ($parsed !== null && !empty($parsed['title']) && $parsed['title'] !== 'New event') {
                $created = ActivityContext::with('mail:llm', fn() => $this->createFromDraft($userId, $msg, [
                    'title' => $parsed['title'],
                    'start' => $parsed['start'],
                    'end' => $parsed['end'],
                    'location' => $parsed['location'],
                    'url' => null,
                    'allDay' => (bool) $parsed['allDay'],
                ], 'llm', $tz));
                if ($created !== null) {
                    return ['tier' => 'llm', 'outcome' => 'created', 'eventId' => $created, 'error' => null];
                }
            }
        }

        return ['tier' => 'none', 'outcome' => 'skipped', 'eventId' => null, 'error' => null];
    }

    /**
     * Normalised mail identity for comparison: lowercased address, angle
     * brackets and display name stripped. "Alice <A@Example.COM>" and
     * "a@example.com" are the same organizer.
     */
    private static function addrKey(mixed $addr): string
    {
        $s = is_string($addr) ? $addr : '';
        if (preg_match('/<([^>]+)>/', $s, $m) === 1) {
            $s = $m[1];
        }
        $s = strtolower(trim($s));
        return str_starts_with($s, 'mailto:') ? substr($s, 7) : $s;
    }

    /**
     * May this message mutate this existing event?
     *
     * An iMIP message is an unauthenticated email. Anyone who learns a UID —
     * which every genuine participant already has, since it travels in the
     * invitation — could otherwise cancel or rewrite the owner's event just by
     * sending mail to the ingest address, and the owner would see no trace: a
     * cancelled meeting looks the same as one that was never accepted.
     *
     * So the organizer recorded when the invitation was FIRST accepted becomes
     * the trusted identity for that UID, and later REQUEST/CANCEL messages must
     * match it. Two things are compared, and either satisfies the check, since
     * mailing lists and calendaring services legitimately send on an
     * organizer's behalf:
     *
     *   - the ORGANIZER inside the iCalendar body, and
     *   - the envelope sender of the mail carrying it.
     *
     * @param array<string,mixed>|null $stored decoded invite_json of the event
     * @return array{0:bool,1:string} [allowed, reason]
     */
    public static function imipMayMutate(?array $stored, array $incoming, string $fromAddr, string $eventStatus = 'confirmed'): array
    {
        $trusted = self::addrKey($stored['organizer']['email'] ?? null);
        // No organizer was ever recorded (an event created locally, or by an
        // older ingest). Nothing to authenticate against, so treat the UID as
        // unowned rather than inventing trust for it.
        if ($trusted === '') {
            return [false, 'no organizer bound to this event'];
        }

        $claimed = self::addrKey($incoming['organizer']['email'] ?? null);
        $sender = self::addrKey($fromAddr);
        if ($claimed !== $trusted && $sender !== $trusted) {
            return [false, 'organizer mismatch'];
        }

        // Replay: a message with a lower SEQUENCE describes an older state of
        // the meeting. Message-ID dedup does not catch this, because a stale
        // body can be resent with a fresh Message-ID.
        $storedSeq = isset($stored['sequence']) ? (int) $stored['sequence'] : 0;
        $incomingSeq = isset($incoming['sequence']) ? (int) $incoming['sequence'] : 0;
        if ($incomingSeq < $storedSeq) {
            return [false, 'stale sequence'];
        }
        // A cancellation is the end of a sequence number: anything carrying the
        // SAME number was written before it. Without this, a REQUEST captured
        // earlier could be replayed after the CANCEL and rewrite the cancelled
        // event (BC-09). Re-issuing a cancelled meeting takes a higher number,
        // which is what organizers' software sends. For a live event an equal
        // number stays acceptable: some calendars resend small edits without
        // bumping it, and the owner now sees and decides every such change.
        if ($eventStatus === 'cancelled' && $incomingSeq <= $storedSeq) {
            return [false, 'stale sequence (event already cancelled)'];
        }

        return [true, ''];
    }

    /** @return array{0:string,1:?int,2?:string}|null [outcome, eventId, reason] */
    private function applyImipEvent(int $userId, string $method, array $ev, string $tz, string $fromAddr = ''): ?array
    {
        $uid = (string) ($ev['uid'] ?? '');
        if ($uid === '') {
            return null;
        }
        $existing = $this->db->one(
            'SELECT * FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL',
            [$userId, $uid]
        );

        // Anything that would MUTATE an event the owner already has must prove
        // it comes from that event's organizer. Creating a new event from an
        // unknown UID stays open — that is what an invitation is.
        if ($existing !== null) {
            $storedInvite = is_string($existing['invite_json'] ?? null)
                ? json_decode((string) $existing['invite_json'], true)
                : null;
            $incoming = is_array($ev['invite'] ?? null) ? $ev['invite'] : [];
            [$allowed, $why] = self::imipMayMutate(
                is_array($storedInvite) ? $storedInvite : null,
                $incoming,
                $fromAddr,
                (string) ($existing['status'] ?? 'confirmed')
            );
            if (!$allowed) {
                // Refused, not silently dropped. Two records, because they have
                // different readers: mail_ingest is the per-message log (reason
                // in `error`; `outcome` is only VARCHAR(32)), and the activity
                // feed is where the owner actually looks — a blocked attempt to
                // cancel their meeting should not live only in worker stdout.
                (new Undo($this->db))->record(
                    $userId,
                    'event',
                    (int) $existing['id'],
                    'refuse',
                    null,
                    null,
                    // Reason stays out of the summary so the row reads on one
                    // line like every other; it lands in the detail line below.
                    'Blocked an emailed ' . ($method === 'CANCEL' ? 'cancellation of' : 'change to')
                        . ' "' . (string) $existing['title'] . '"',
                    [
                        'reason' => $why,
                        'method' => $method,
                        'uid' => $uid,
                        'from' => $fromAddr,
                        'claimedOrganizer' => $incoming['organizer']['email'] ?? null,
                        'boundOrganizer' => is_array($storedInvite) ? ($storedInvite['organizer']['email'] ?? null) : null,
                    ]
                );
                return ['refused', (int) $existing['id'], $why];
            }
        }

        // From here on, a message about an event the owner ALREADY HAS never
        // touches the calendar. Passing the organizer check above only means
        // the addresses match, and a sender writes those (BC-07); so the change
        // waits in the Review queue, showing what it would do, until the owner
        // accepts it. ReviewQueue::accept applies it then, through Events::patch.
        if ($method === 'CANCEL') {
            if ($existing === null) {
                return ['skipped', null];
            }
            $held = $this->review->holdInviteChange($userId, $existing, $uid, 'CANCEL', [], $ev['invite'] ?? null, $fromAddr);
            return [$held === null ? 'unchanged' : 'held', (int) $existing['id']];
        }

        $startUtc = new \DateTimeImmutable((string) $ev['start_utc'], Time::utc());
        $endUtc = new \DateTimeImmutable((string) $ev['end_utc'], Time::utc());
        $tzid = is_string($ev['tzid'] ?? null) && $ev['tzid'] !== '' ? $ev['tzid'] : $tz;
        $zone = Time::zone($tzid);
        $fields = [
            'title' => (string) ($ev['title'] ?? '(untitled)'),
            'start' => Time::iso($startUtc->setTimezone($zone)),
            'end' => Time::iso($endUtc->setTimezone($zone)),
            'allDay' => (bool) ($ev['all_day'] ?? false),
            'tzid' => $tzid,
            'location' => isset($ev['location']) && $ev['location'] !== '' ? (string) $ev['location'] : null,
            'description' => isset($ev['description']) && $ev['description'] !== '' ? (string) $ev['description'] : null,
            'rrule' => isset($ev['rrule']) && $ev['rrule'] !== '' ? (string) $ev['rrule'] : null,
        ];

        if ($existing !== null) {
            $held = $this->review->holdInviteChange($userId, $existing, $uid, 'REQUEST', $fields, $ev['invite'] ?? null, $fromAddr);
            return [$held === null ? 'unchanged' : 'held', (int) $existing['id']];
        }

        $calendarId = $this->inviteCalendarId($userId);
        $occurrence = $this->events->create($userId, $fields + ['calendarId' => $calendarId, 'uid' => $uid]);
        $eventId = (int) $occurrence['eventId'];
        self::storeInvite($this->db, $eventId, $ev['invite'] ?? null, false);
        return ['created', $eventId];
    }

    /** @param array{title:string,start:string,end:?string,location:?string,url:?string,allDay?:bool} $draft */
    private function createFromDraft(int $userId, array $msg, array $draft, string $tier, string $tz): ?int
    {
        try {
            $start = new \DateTimeImmutable($draft['start']);
        } catch (\Exception) {
            return null;
        }
        $allDay = (bool) ($draft['allDay'] ?? (strlen($draft['start']) <= 10));
        try {
            $end = isset($draft['end']) && $draft['end'] !== null && $draft['end'] !== ''
                ? new \DateTimeImmutable($draft['end'])
                : $start->add(new \DateInterval($allDay ? 'P1D' : 'PT1H'));
        } catch (\Exception) {
            $end = $start->add(new \DateInterval($allDay ? 'P1D' : 'PT1H'));
        }
        if ($end <= $start) {
            $end = $start->add(new \DateInterval($allDay ? 'P1D' : 'PT1H'));
        }

        // Synthetic UID keyed on the message: reprocessing can't duplicate.
        $uid = 'mail-' . sha1($msg['messageId'] . '|' . $draft['title']);
        $existing = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND uid = ?', [$userId, $uid]);
        if ($existing !== null) {
            return null;
        }

        $zone = Time::zone($tz);
        $occurrence = $this->events->create($userId, [
            'calendarId' => $this->inviteCalendarId($userId),
            'uid' => $uid,
            'title' => $draft['title'],
            'start' => Time::iso($start->setTimezone($zone)),
            'end' => Time::iso($end->setTimezone($zone)),
            'allDay' => $allDay,
            'tzid' => $tz,
            'location' => $draft['location'],
            'url' => $draft['url'] ?? null,
            'description' => $draft['description'] ?? null,
        ]);
        $eventId = (int) $occurrence['eventId'];
        self::storeInvite($this->db, $eventId, [
            'method' => $tier,
            'organizer' => ['email' => strtolower($msg['from']), 'name' => null],
            'attendees' => [],
            'sequence' => 0,
            'myPartstat' => 'NEEDS-ACTION',
        ], false);
        return $eventId;
    }

    /**
     * Record the invitation block on an event. Its `sequence` is the replay
     * watermark imipMayMutate compares against, so this runs for EVERY accepted
     * message, a CANCEL included (BC-09: a cancellation that did not advance it
     * left the door open to an older REQUEST). Static because the Review queue
     * applies held changes and must store the block exactly the same way.
     */
    public static function storeInvite(Db $db, int $eventId, ?array $invite, bool $keepPartstat): void
    {
        if ($invite === null) {
            return;
        }
        if ($keepPartstat) {
            $prev = $db->scalar('SELECT invite_json FROM events WHERE id = ?', [$eventId]);
            $prevInvite = is_string($prev) ? json_decode($prev, true) : null;
            if (is_array($prevInvite) && isset($prevInvite['myPartstat'])) {
                $invite['myPartstat'] = $prevInvite['myPartstat'];
            }
        }
        $db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), $eventId]);
    }

    /** Find-or-create the local "Invitations" calendar. */
    public function inviteCalendarId(int $userId): int
    {
        $id = $this->db->scalar(
            "SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' AND name = ?",
            [$userId, self::INVITE_CALENDAR]
        );
        if ($id !== null) {
            return (int) $id;
        }
        return $this->db->insert('calendars', [
            'user_id' => $userId,
            'name' => self::INVITE_CALENDAR,
            'kind' => 'local',
            'color' => self::INVITE_COLOR,
            'visible' => 1,
        ]);
    }

    // ---- RSVP ----------------------------------------------------------

    /** Pure iMIP REPLY builder (docs/email-ingest.md). */
    public static function buildReplyIcs(
        string $uid,
        string $organizerEmail,
        string $attendeeEmail,
        string $partstat,
        int $sequence,
        string $summary,
        \DateTimeImmutable $now
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . Ics::PRODID,
            'METHOD:REPLY',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->setTimezone(Time::utc())->format('Ymd\THis\Z'),
            'ORGANIZER:mailto:' . $organizerEmail,
            'ATTENDEE;PARTSTAT=' . strtoupper($partstat) . ':mailto:' . $attendeeEmail,
            'SEQUENCE:' . $sequence,
            'SUMMARY:' . Ics::escape($summary),
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map(Ics::fold(...), $lines)) . "\r\n";
    }

    /**
     * RSVP to an ingested invitation: records myPartstat and emails an iMIP
     * REPLY to the organizer (best-effort; recording never fails on send).
     *
     * @return array{myPartstat: string, sent: bool}
     */
    public function rsvp(int $userId, int $eventId, string $answer, \BetterCal\Infra\EmailSender $mailer, array $cfg): array
    {
        $map = ['accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentative' => 'TENTATIVE'];
        $partstat = $map[strtolower($answer)] ?? null;
        if ($partstat === null) {
            throw \BetterCal\Http\HttpError::badRequest('answer must be accepted, declined or tentative');
        }
        $row = $this->db->one(
            'SELECT id, calendar_id, uid, title, invite_json FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$eventId, $userId]
        );
        if ($row === null || $row['invite_json'] === null) {
            throw \BetterCal\Http\HttpError::notFound('No invitation on this event');
        }
        $invite = is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true);
        $invite['myPartstat'] = $partstat;
        $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), $eventId]);
        ActivityContext::with('rsvp', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            $eventId,
            'update',
            null,
            null,
            "RSVP'd " . ucfirst(strtolower($partstat)) . " to '" . (string) $row['title'] . "'"
        ));
        // Journal so other open clients see the RSVP on their next cursor poll.
        \BetterCal\Dav\ChangeLog::record($this->db, (int) $row['calendar_id'], (string) $row['uid'], \BetterCal\Dav\ChangeLog::OP_MODIFY);

        $sent = false;
        $organizer = $invite['organizer']['email'] ?? null;
        if (is_string($organizer) && $organizer !== '' && ($invite['method'] ?? '') === 'REQUEST') {
            $me = (string) ($cfg['rsvp_smtp']['from'] ?? '');
            if ($me === '') {
                $me = (string) ($cfg['smtp']['from'] ?? '');
            }
            if ($me !== '') {
                $ics = self::buildReplyIcs(
                    (string) $row['uid'],
                    $organizer,
                    $me,
                    $partstat,
                    (int) ($invite['sequence'] ?? 0),
                    (string) $row['title'],
                    Time::nowUtc()
                );
                $verb = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Tentative'][$partstat];
                $sent = $mailer->sendImipReply(
                    $organizer,
                    $verb . ': ' . (string) $row['title'],
                    $ics,
                    $verb . ': ' . (string) $row['title']
                );
            }
        }
        return ['myPartstat' => $partstat, 'sent' => $sent];
    }

    private function alreadyProcessed(string $messageId): bool
    {
        // A 'started' row is this same run's begin(), not a previous outcome.
        return $this->db->scalar(
            'SELECT id FROM mail_ingest WHERE message_id = ? AND outcome <> ?',
            [mb_substr($messageId, 0, 255), self::OUTCOME_STARTED]
        ) !== null;
    }
}
