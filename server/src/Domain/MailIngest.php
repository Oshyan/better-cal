<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;
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
    }

    // ---- Pure helpers (unit-tested, no I/O) ----------------------------

    /**
     * Parse an iMIP payload: calendar METHOD plus per-event invite fields on
     * top of the shared Ics::parse event shape.
     *
     * @return array{method: string, events: list<array<string,mixed>>}|null
     */
    public static function parseImip(string $ics): ?array
    {
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
            $uid = isset($vevent->UID) ? (string) $vevent->UID : null;
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
        if (preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~si', $html, $m) < 1) {
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
        $html = preg_replace('~<(script|style)[^>]*>.*?</\1>~si', ' ', $html) ?? $html;
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
        if (stripos($text, 'Forwarded message') === false) {
            return $text;
        }
        return preg_replace(
            '/-{3,}\s*Forwarded message\s*-{3,}\s*From:.{0,400}?To:\s*<?[^\s<>]+@[^\s<>]+>?/su',
            ' ',
            $text,
            1
        ) ?? $text;
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
        try {
            $result = $this->runTiers($userId, $msg, $tz);
        } catch (\Throwable $e) {
            $result = ['tier' => 'none', 'outcome' => 'error', 'eventId' => null, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
        $this->db->run(
            'INSERT IGNORE INTO mail_ingest (message_id, subject, from_addr, tier, outcome, event_id, error)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                mb_substr($msg['messageId'], 0, 255),
                mb_substr($msg['subject'], 0, 500),
                mb_substr($msg['from'], 0, 255),
                $result['tier'],
                $result['outcome'],
                $result['eventId'],
                $result['error'],
            ]
        );
        return $result;
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
                $outcome = $this->applyImipEvent($userId, $imip['method'], $ev, $tz);
                if ($outcome !== null) {
                    return ['tier' => 'imip', 'outcome' => $outcome[0], 'eventId' => $outcome[1], 'error' => null];
                }
            }
        }

        // Tier 2: schema.org markup.
        if ($msg['html'] !== null) {
            foreach (self::extractLdJsonEvents($msg['html']) as $draft) {
                $created = $this->createFromDraft($userId, $msg, $draft, 'markup', $tz);
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
            $created = $this->createFromDraft($userId, $msg, [
                'title' => $draft['title'],
                'start' => $draft['start'],
                'end' => $draft['end'],
                'allDay' => $draft['allDay'],
                'location' => $draft['location'],
                'description' => $draft['description'],
                'url' => null,
            ], 'gcal-link', $tz);
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
                $created = $this->createFromDraft($userId, $msg, [
                    'title' => $parsed['title'],
                    'start' => $parsed['start'],
                    'end' => $parsed['end'],
                    'location' => $parsed['location'],
                    'url' => null,
                    'allDay' => (bool) $parsed['allDay'],
                ], 'llm', $tz);
                if ($created !== null) {
                    return ['tier' => 'llm', 'outcome' => 'created', 'eventId' => $created, 'error' => null];
                }
            }
        }

        return ['tier' => 'none', 'outcome' => 'skipped', 'eventId' => null, 'error' => null];
    }

    /** @return array{0:string,1:?int}|null [outcome, eventId] */
    private function applyImipEvent(int $userId, string $method, array $ev, string $tz): ?array
    {
        $uid = (string) ($ev['uid'] ?? '');
        if ($uid === '') {
            return null;
        }
        $existing = $this->db->one(
            'SELECT id, calendar_id FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL',
            [$userId, $uid]
        );

        if ($method === 'CANCEL') {
            if ($existing === null) {
                return ['skipped', null];
            }
            $this->db->run("UPDATE events SET status = 'cancelled' WHERE id = ?", [(int) $existing['id']]);
            \BetterCal\Dav\ChangeLog::record($this->db, (int) $existing['calendar_id'], $uid, \BetterCal\Dav\ChangeLog::OP_MODIFY);
            return ['cancelled', (int) $existing['id']];
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
            $this->events->patch($userId, (int) $existing['id'], $fields);
            $this->storeInvite((int) $existing['id'], $ev['invite'] ?? null, true);
            return ['updated', (int) $existing['id']];
        }

        $calendarId = $this->inviteCalendarId($userId);
        $occurrence = $this->events->create($userId, $fields + ['calendarId' => $calendarId, 'uid' => $uid]);
        $eventId = (int) $occurrence['eventId'];
        $this->storeInvite($eventId, $ev['invite'] ?? null, false);
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
        $this->storeInvite($eventId, [
            'method' => $tier,
            'organizer' => ['email' => strtolower($msg['from']), 'name' => null],
            'attendees' => [],
            'sequence' => 0,
            'myPartstat' => 'NEEDS-ACTION',
        ], false);
        return $eventId;
    }

    private function storeInvite(int $eventId, ?array $invite, bool $keepPartstat): void
    {
        if ($invite === null) {
            return;
        }
        if ($keepPartstat) {
            $prev = $this->db->scalar('SELECT invite_json FROM events WHERE id = ?', [$eventId]);
            $prevInvite = is_string($prev) ? json_decode($prev, true) : null;
            if (is_array($prevInvite) && isset($prevInvite['myPartstat'])) {
                $invite['myPartstat'] = $prevInvite['myPartstat'];
            }
        }
        $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), $eventId]);
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
        return $this->db->scalar('SELECT id FROM mail_ingest WHERE message_id = ?', [mb_substr($messageId, 0, 255)]) !== null;
    }
}
