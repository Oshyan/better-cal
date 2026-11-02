<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/**
 * Changes that arrived from outside and wait for the owner's decision.
 *
 * Why this exists (BC-07): an emailed REQUEST or CANCEL for an invitation the
 * owner already has is an unauthenticated message. The organizer check in
 * MailIngest::imipMayMutate compares the ORGANIZER line and the From address
 * with the organizer recorded at first acceptance, and a sender writes both.
 * Anyone who knew a meeting's UID and organizer could cancel or move it, and
 * the calendar would just change. So such a message is no longer applied on
 * arrival: it is held here, with exactly what it would change, and nothing
 * happens to the calendar until the owner presses Accept.
 *
 * The rule that makes a queue worth trusting is the same one Proposals has:
 * holding never touches the calendar, accepting goes through the ordinary
 * Events domain (same validation, same activity entry, same undo), and only
 * an applied decision can make a competing emailed revision stale.
 *
 * The Review page also lists plugin proposals, invitations awaiting an RSVP,
 * possible duplicates, and paused subscriptions. Held changes and bounded
 * mail/model-limit notices are stored here; the other kinds already have a home.
 */
final class ReviewQueue
{
    public const KIND_INVITE_NEW = 'invite_new';
    public const KIND_INVITE_CHANGE = 'invite_change';
    public const KIND_MAIL_LIMIT = 'mail_limit';
    public const KIND_MODEL_LIMIT = 'model_limit';
    public const KIND_MAIL_ERROR = 'mail_error';

    private const PREVIEW_CHARS = 400;
    /** Open competing change candidates kept for one meeting. */
    public const CHANGES_PER_EVENT = 3;
    /** Unreadable-email notices shown one by one; more fold into one counted notice. */
    public const MAIL_ERROR_NOTICES = 20;

    public function __construct(private readonly Db $db, private readonly Events $events)
    {
    }

    // ---- Pure ----------------------------------------------------------

    /**
     * What an emailed change would do to the event as it stands, as rows the UI
     * can show: [{field, label, from, to}]. Pure; unit-tested.
     *
     * An empty list for a REQUEST means the message changes nothing the owner
     * can see (a re-send, an attendee-list update): not worth a decision.
     *
     * @param array<string,mixed> $row    the events row as stored
     * @param array<string,mixed> $fields the Events::patch payload the message maps to
     * @return list<array{field:string,label:string,from:?string,to:?string}>
     */
    public static function inviteDiff(array $row, string $method, array $fields): array
    {
        if ($method === 'CANCEL') {
            return (string) ($row['status'] ?? 'confirmed') === 'cancelled'
                ? []
                : [['field' => 'status', 'label' => 'Status', 'from' => (string) ($row['status'] ?? 'confirmed'), 'to' => 'cancelled']];
        }
        $tz = Time::zone((string) ($row['tzid'] ?? 'UTC'));
        $allDay = (int) ($row['all_day'] ?? 0) === 1;
        $stored = static function (string $col) use ($row, $tz, $allDay): string {
            $local = Time::fromDb((string) $row[$col])->setTimezone($tz);
            return $allDay ? $local->format('Y-m-d') : Time::iso($local);
        };
        $instant = static fn(string $iso): int => (new \DateTimeImmutable($iso))->getTimestamp();

        $out = [];
        $newAllDay = (bool) ($fields['allDay'] ?? false);
        if ($newAllDay !== $allDay) {
            $out[] = ['field' => 'allDay', 'label' => 'All day', 'from' => $allDay ? 'yes' : 'no', 'to' => $newAllDay ? 'yes' : 'no'];
        }
        foreach (['start' => 'Starts', 'end' => 'Ends'] as $key => $label) {
            $from = $stored($key . '_utc');
            $to = (string) ($fields[$key] ?? '');
            $same = $newAllDay && $allDay
                ? substr($to, 0, 10) === $from
                : ($to !== '' && !$allDay && !$newAllDay && $instant($to) === $instant($from));
            if (!$same) {
                $out[] = ['field' => $key, 'label' => $label, 'from' => $from, 'to' => $newAllDay ? substr($to, 0, 10) : $to];
            }
        }
        $text = static fn(mixed $v): ?string => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        foreach (['title' => 'Title', 'location' => 'Location', 'rrule' => 'Repeats'] as $key => $label) {
            $from = $text($row[$key] ?? null);
            $to = $text($fields[$key] ?? null);
            if ($from !== $to) {
                $out[] = ['field' => $key, 'label' => $label, 'from' => $from, 'to' => $to];
            }
        }
        // Descriptions are long and often HTML: say that it changed and show a
        // plain-text preview of the new one, not two walls of markup.
        $plain = static fn(mixed $v): ?string => $text($v) === null
            ? null
            : mb_substr(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $v)))), 0, self::PREVIEW_CHARS);
        if ($plain($row['description'] ?? null) !== $plain($fields['description'] ?? null)) {
            $out[] = ['field' => 'description', 'label' => 'Description', 'from' => $plain($row['description'] ?? null), 'to' => $plain($fields['description'] ?? null)];
        }
        if ((string) ($row['status'] ?? 'confirmed') === 'cancelled') {
            $out[] = ['field' => 'status', 'label' => 'Status', 'from' => 'cancelled', 'to' => 'confirmed'];
        }
        return $out;
    }

    /**
     * What an email about ONE occurrence would change, compared with that
     * occurrence as the series has it. A cancellation of an occurrence the
     * series already skips changes nothing. Pure; unit-tested.
     *
     * @return list<array{field:string,label:string,from:?string,to:?string}>
     */
    public static function occurrenceDiff(array $series, string $occurrence, string $method, array $fields, ?array $override = null, ?string $homeTzid = null): array
    {
        $skipped = in_array($occurrence, Recurrence::decodeExdates($series['exdates_json'] ?? null), true)
            || ($override !== null && (string) ($override['status'] ?? '') === 'cancelled' && ($method === 'CANCEL' || ($fields['status'] ?? null) === 'cancelled'));
        if ($method === 'CANCEL' || ($fields['status'] ?? null) === 'cancelled') {
            return $skipped ? [] : [['field' => 'occurrence', 'label' => 'Occurrence', 'from' => self::occurrenceLabel($series, $occurrence, $homeTzid), 'to' => 'cancelled']];
        }
        if ($skipped) {
            return [];
        }
        // Compared with the occurrence as the calendar shows it: its own
        // exception when it has one (moved or edited before), else the series.
        if ($override !== null) {
            return self::inviteDiff(['rrule' => null] + $override, 'REQUEST', ['rrule' => null] + $fields);
        }
        $length = Time::fromDb((string) $series['end_utc'])->getTimestamp() - Time::fromDb((string) $series['start_utc'])->getTimestamp();
        $as = ['start_utc' => $occurrence, 'end_utc' => Time::fromDb($occurrence)->add(new \DateInterval('PT' . max(0, $length) . 'S'))->format(Time::DB), 'rrule' => null] + $series;
        return self::inviteDiff($as, 'REQUEST', ['rrule' => null] + $fields);
    }

    /** The exception row for one occurrence of a series, or null. */
    public static function occurrenceRow(Db $db, int $seriesId, string $occurrence): ?array
    {
        return $db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [$seriesId, $occurrence]
        );
    }

    /**
     * "Tue 3 Nov 2026": an all-day occurrence's date, or a timed one's day in
     * the series' zone (the owner's Home zone for a series stored in UTC, so
     * a 6 PM occurrence isn't named by the next day).
     */
    public static function occurrenceLabel(array $series, string $occurrence, ?string $homeTzid = null): string
    {
        $at = Time::fromDb($occurrence);
        if ((int) ($series['all_day'] ?? 0) === 1) {
            return $at->setTimezone(Time::zone((string) ($series['tzid'] ?? 'UTC')))->format('D j M Y');
        }
        return $at->setTimezone(Time::displayZone((string) ($series['tzid'] ?? 'UTC'), $homeTzid))->format('D j M Y');
    }

    // ---- Holding -------------------------------------------------------

    /**
     * Hold a first-time emailed invitation without creating an event. Distinct
     * candidates for the same sender-controlled UID remain visible: an
     * unauthenticated high SEQUENCE must not hide the genuine invitation. An
     * exact transport replay is coalesced.
     *
     * @param array<string,mixed> $fields Events::create payload without calendarId/uid
     * @param array<string,mixed>|null $invite organizer, attendees and sequence
     * @param ?string $occurrence RECURRENCE-ID (UTC, DB format) when the email
     *   changes one occurrence of a series that isn't on the calendar (#192).
     *   $fields is then that occurrence's Events::patch payload, never made
     *   an event of its own: once the series is added, reconcilePending turns
     *   the item into a change to that occurrence.
     */
    public function holdNewInvitation(int $userId, string $uid, array $fields, ?array $invite, string $fromAddr, string $tier = 'imip', string $mode = 'approved', ?string $occurrence = null): int
    {
        $invite = is_array($invite) ? $invite : [];
        $sequence = (int) ($invite['sequence'] ?? 0);
        $candidateKey = hash('sha256', json_encode([$uid, $fromAddr, $fields, $invite, $occurrence], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '');
        $who = (string) ($invite['organizer']['name'] ?? '') ?: (string) ($invite['organizer']['email'] ?? '') ?: $fromAddr;
        $cancelsDay = $occurrence !== null && ($invite['method'] ?? '') === 'CANCEL';
        if ($occurrence !== null) {
            $summary = ($who !== '' ? $who : 'The organizer') . ($cancelsDay ? ' cancelled' : ' changed') . ' one occurrence of a repeating event that is not on your calendar yet.'
                . ' Once that series is added, this becomes a ' . ($cancelsDay ? 'cancellation of' : 'change to') . ' that occurrence. Nothing has been added.';
        } else {
            $summary = $mode === 'review'
                ? 'Your email setting holds every new event for Review. This event has not been added.'
                : 'Event from ' . ($fromAddr ?: 'an unreadable sender') . '. This sender is not approved, so the event has not been added.';
        }
        $sourceKey = mb_substr($uid, 0, 255);

        return (new Undo($this->db))->write($userId, function () use ($userId, $uid, $fields, $invite, $fromAddr, $sequence, $candidateKey, $summary, $sourceKey, $tier, $occurrence, $cancelsDay): int {
            foreach ($this->db->all(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC",
                [$userId, self::KIND_INVITE_NEW, $sourceKey]
            ) as $open) {
                if ((string) (self::payload($open)['candidateKey'] ?? '') === $candidateKey) {
                    return (int) $open['id'];
                }
            }
            $held = $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_INVITE_NEW,
                'event_id' => null,
                'source_key' => $sourceKey,
                'title' => mb_substr((string) ($fields['title'] ?? '(untitled)'), 0, 300),
                'summary' => mb_substr($summary, 0, 1000),
                'payload_json' => json_encode([
                    'method' => $invite['method'] ?? 'REQUEST',
                    'tier' => $tier,
                    'uid' => $uid,
                    'from' => $fromAddr,
                    'fields' => $fields,
                    'invite' => $invite,
                    'sequence' => $sequence,
                    'candidateKey' => $candidateKey,
                ] + ($occurrence !== null ? ['occurrence' => $occurrence] : []), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
            $this->journalHeld($userId, $held, 'create', null, 'Held an emailed ' . ($occurrence !== null ? ($cancelsDay ? 'cancellation of one occurrence of' : 'change to one occurrence of') : 'invitation to')
                . ' "' . mb_substr((string) ($fields['title'] ?? '(untitled)'), 0, 200) . '" for Review');
            return $held;
        });
    }

    /**
     * Is a first version of this series itself waiting in Review, not only
     * a changed day of it? A cancelled day of it that arrives meanwhile
     * waits with it (MailIngest::applyImipEvent), whatever the owner decided
     * about an earlier version.
     */
    public function seriesWaiting(int $userId, string $uid): bool
    {
        foreach ($this->db->all(
            "SELECT payload_json FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
            [$userId, self::KIND_INVITE_NEW, mb_substr($uid, 0, 255)]
        ) as $open) {
            if (!isset(self::payload($open)['occurrence'])) return true;
        }
        return false;
    }

    /**
     * Why a day of a series that is neither on the calendar nor waiting is
     * nothing to decide, or null when nothing is known of the series yet,
     * so a cancelled day arriving before it waits for it
     * (MailIngest::applyImipEvent). The owner dismissed its invitation in
     * Review, the organizer cancelled it while it waited (cancelWaiting),
     * or the owner deleted the series: a cancelled day of any of these
     * would wait for a series that is not coming back, and the day isn't
     * shown anyway. The latest closed invitation decides between the first
     * two, and either is read before a deletion: a series added, then
     * taken back by undo and dismissed, was dismissed, not deleted.
     */
    public function seriesRefused(int $userId, string $uid): ?string
    {
        foreach ($this->db->all(
            "SELECT status, payload_json FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status IN ('dismissed', 'gone') ORDER BY id DESC",
            [$userId, self::KIND_INVITE_NEW, mb_substr($uid, 0, 255)]
        ) as $closed) {
            $payload = self::payload($closed);
            if (isset($payload['occurrence'])) continue;
            if ((string) $closed['status'] === 'dismissed') return 'the series was dismissed in Review';
            if (!empty($payload['organizerCancelled'])) return 'the organizer cancelled the series before it was added';
        }
        if ($this->db->scalar(
            'SELECT id FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NOT NULL AND recurrence_parent_id IS NULL LIMIT 1',
            [$userId, $uid]
        ) !== null) {
            return 'the series was deleted from the calendar';
        }
        return null;
    }

    /**
     * The series with this UID was just put on the calendar without a
     * Review decision (added automatically from an approved sender,
     * MailIngest::createNew): the days of it held before it (#192) become
     * changes to their occurrences, as accepting the series from Review
     * makes them. Runs inside the add's write and its group, so the add's
     * one entry carries the items as they were and are, and undoing it
     * puts them back as they waited.
     */
    public function seriesAdded(int $userId, int $eventId, string $uid): void
    {
        $sourceKey = mb_substr($uid, 0, 255);
        $rows = fn(): array => $this->db->all('SELECT * FROM review_items WHERE user_id = ? AND source_key = ? AND kind IN (?, ?) ORDER BY id',
            [$userId, $sourceKey, self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE]);
        $before = $rows();
        if (array_filter($before, static fn(array $row): bool => (string) $row['status'] === 'open') === []) {
            return;
        }
        $this->reconcilePending($userId, $eventId, $sourceKey);
        (new Undo($this->db))->record($userId, 'review', (int) $before[0]['id'], 'update', ['review_items' => $before], ['review_items' => $rows()]);
    }

    /** Are days of this series held before it (#192) still waiting for it? */
    public function daysHeldFor(int $userId, string $uid): bool
    {
        foreach ($this->db->all(
            "SELECT payload_json FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
            [$userId, self::KIND_INVITE_NEW, mb_substr($uid, 0, 255)]
        ) as $open) {
            if (isset(self::payload($open)['occurrence'])) return true;
        }
        return false;
    }

    /**
     * The organizer cancelled a whole series whose invitation is still
     * waiting in Review (MailIngest::applyImipEvent): every open invitation
     * to it from that organizer or sender closes, saying so, and once none
     * waits the days held before it close with them. A cancellation from
     * anyone else leaves the invitation waiting. Closing a held item
     * changes no event, so the entry is a note, as a dismissal's is, that
     * touches the items it closed. Returns how many invitations closed.
     *
     * @param array<string,mixed> $invite the cancellation's invite block
     */
    public function cancelWaiting(int $userId, string $uid, array $invite, string $fromAddr): int
    {
        $sourceKey = mb_substr($uid, 0, 255);
        $claims = array_filter([MailPolicy::mailbox($invite['organizer']['email'] ?? null), MailPolicy::mailbox($fromAddr)]);
        if ($claims === [] || !$this->seriesWaiting($userId, $uid)) {
            return 0;
        }
        return (new Undo($this->db))->write($userId, function () use ($userId, $uid, $sourceKey, $claims): int {
            $closed = [];
            $title = '';
            foreach ($this->db->all(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id",
                [$userId, self::KIND_INVITE_NEW, $sourceKey]
            ) as $open) {
                $payload = self::payload($open);
                if (isset($payload['occurrence'])) continue;
                $theirs = array_filter([MailPolicy::mailbox($payload['invite']['organizer']['email'] ?? null), MailPolicy::mailbox($payload['from'] ?? null)]);
                if (array_intersect($claims, $theirs) === []) continue;
                $who = (string) ($payload['invite']['organizer']['name'] ?? '') ?: (string) ($payload['invite']['organizer']['email'] ?? '') ?: (string) ($payload['from'] ?? '');
                $payload['organizerCancelled'] = true;
                $this->db->update('review_items', [
                    'status' => 'gone', 'decided_at' => Time::nowDb(),
                    'summary' => mb_substr(($who !== '' ? $who : 'The organizer') . ' cancelled this before you added it. Nothing was added.', 0, 1000),
                    'payload_json' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
                ], 'id = ?', [(int) $open['id']]);
                $closed[] = (int) $open['id'];
                $title = (string) $open['title'];
            }
            if ($closed === []) {
                return 0;
            }
            $invitations = count($closed);
            if (!$this->seriesWaiting($userId, $uid)) {
                foreach ($this->db->all(
                    "SELECT id, payload_json FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                    [$userId, self::KIND_INVITE_NEW, $sourceKey]
                ) as $day) {
                    if (!isset(self::payload($day)['occurrence'])) continue;
                    $this->close((int) $day['id'], 'superseded');
                    $closed[] = (int) $day['id'];
                }
            }
            (new Undo($this->db))->record($userId, 'review', $closed[0], 'update', null, null,
                'Closed the emailed invitation to "' . mb_substr($title, 0, 200) . '" waiting in Review: the organizer cancelled it',
                touches: array_map(static fn(int $id): array => [Undo::keyOf('review_items', ['id' => $id]), '*'], $closed));
            return $invitations;
        });
    }

    /**
     * An emailed version held for Review, in Activity (log only: the item is
     * yours to decide, not to undo). What it made or rewrote is the ground of
     * an older undo: an accept undone would otherwise put back over a newer
     * version, or remove the event one now stands on.
     */
    private function journalHeld(int $userId, int $itemId, string $op, ?int $eventId, string $summary): void
    {
        (new Undo($this->db))->record($userId, 'review', $itemId, $op, null, null, $summary,
            touches: array_merge([[Undo::keyOf('review_items', ['id' => $itemId]), '*']],
                $eventId !== null ? [[Undo::keyOf('events', ['id' => $eventId]), '+']] : []));
    }

    /**
     * Hold an emailed change to an existing invitation. Only an applied
     * sequence is authoritative. Keep distinct pending candidates side by
     * side so a forged high sequence cannot hide a genuine change. Exact
     * transport replays are coalesced.
     * Returns null when there is nothing to decide or the bounded queue is full.
     *
     * @param array<string,mixed> $event  the existing events row
     * @param array<string,mixed> $fields Events::patch payload (ignored for CANCEL)
     * @param array<string,mixed>|null $invite the incoming invite block (organizer, attendees, sequence)
     * @param ?string $occurrence RECURRENCE-ID (UTC, DB format) when the email
     *   is about one occurrence of the series, never the whole of it.
     *   Accepting then skips that occurrence (a cancellation) or changes just
     *   that occurrence, as its own exception (#155).
     */
    public function holdInviteChange(
        int $userId,
        array $event,
        string $uid,
        string $method,
        array $fields,
        ?array $invite,
        string $fromAddr,
        bool $sequenceAuthoritative = true,
        ?string $occurrence = null,
    ): ?int {
        $diff = $occurrence === null
            ? self::inviteDiff($event, $method, $fields)
            : self::occurrenceDiff($event, $occurrence, $method, $fields, self::occurrenceRow($this->db, (int) $event['id'], $occurrence), Settings::homeTzid($this->db, $userId));
        if ($diff === []) {
            return null;
        }
        $invite = is_array($invite) ? $invite : [];
        $candidateKey = hash('sha256', json_encode(
            [$uid, $method, $fromAddr, $method === 'CANCEL' ? null : $fields, $invite, $occurrence],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        ) ?: '');
        $title = (string) ($event['title'] ?? '(untitled)');
        $who = (string) ($invite['organizer']['name'] ?? '') ?: (string) ($invite['organizer']['email'] ?? '') ?: $fromAddr;
        $changed = implode(', ', array_map(static fn(array $d): string => strtolower($d['label']), $diff));
        if ($occurrence !== null) {
            $day = self::occurrenceLabel($event, $occurrence, Settings::homeTzid($this->db, $userId));
            $summary = $method === 'CANCEL'
                ? ($who !== '' ? $who : 'The organizer') . " cancelled the $day occurrence. Accepting skips just that one."
                : ($who !== '' ? $who : 'The organizer') . " changed the $day occurrence: $changed. Accepting changes just that one.";
        } else {
            $summary = $method === 'CANCEL'
                ? ($who !== '' ? "$who cancelled this." : 'The organizer cancelled this.')
                : ($who !== '' ? "$who changed: " : 'Changed: ') . $changed . '.';
        }

        return (new Undo($this->db))->write($userId, function () use ($userId, $event, $uid, $method, $fields, $invite, $fromAddr, $diff, $title, $summary, $candidateKey, $sequenceAuthoritative, $occurrence): ?int {
            $sourceKey = mb_substr($uid, 0, 255);
            $open = $this->db->all(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC",
                [$userId, self::KIND_INVITE_CHANGE, $sourceKey]
            );
            foreach ($open as $pending) {
                if ((string) (self::payload($pending)['candidateKey'] ?? '') === $candidateKey) {
                    return (int) $pending['id'];
                }
            }
            $admitted = (new MailAdmission($this->db))->admitHeldChange($userId, $candidateKey, $fromAddr);
            if (!$admitted['allowed']) {
                $this->holdMailLimit($userId, (string) $admitted['code'], (string) $admitted['message'], (string) ($admitted['retryAt'] ?? ''), $title, $fromAddr);
                return null;
            }
            $row = [
                'title' => mb_substr($title, 0, 300),
                'summary' => mb_substr($summary, 0, 1000),
                'payload_json' => json_encode([
                    'method' => $method,
                    'uid' => $uid,
                    'from' => $fromAddr,
                    'fields' => $method === 'CANCEL' ? null : $fields,
                    'invite' => $invite,
                    'diff' => $diff,
                    'sequenceAuthoritative' => $sequenceAuthoritative,
                    'candidateKey' => $candidateKey,
                ] + ($occurrence !== null ? ['occurrence' => $occurrence] : []), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ];
            // One meeting keeps at most a few competing candidates: the first
            // ones received stay, and the newest slot follows the latest
            // claim, so one known UID cannot fill the whole Review ceiling.
            $journal = fn(int $id, string $op) => $this->journalHeld($userId, $id, $op, (int) $event['id'],
                'Held an emailed ' . ($method === 'CANCEL' ? 'cancellation of' : 'change to') . ' "' . mb_substr($title, 0, 200) . '" for Review');
            if (count($open) >= self::CHANGES_PER_EVENT) {
                $this->db->update('review_items', $row + ['created_at' => Time::nowDb()], 'id = ?', [(int) $open[0]['id']]);
                $journal((int) $open[0]['id'], 'update');
                return (int) $open[0]['id'];
            }
            // An unaccepted sequence is only a sender claim, even when the
            // organizer matches. Every distinct candidate consumes a slot.
            if ($this->pendingInvitationCount($userId) >= Limits::get('MAIL_PENDING_INVITATIONS')) {
                $this->holdMailLimit(
                    $userId,
                    'event_pending',
                    'New emailed invitation decisions paused because Review already holds the maximum number.',
                    '',
                    $title,
                    $fromAddr,
                );
                return null;
            }
            $held = $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_INVITE_CHANGE,
                'event_id' => (int) $event['id'],
                'source_key' => $sourceKey,
            ] + $row);
            $journal($held, 'create');
            return $held;
        });
    }

    /**
     * One bounded, owner-visible notice per mail limit. Repeated hostile mail
     * updates a counter on the same row instead of growing Review without bound.
     */
    public function holdMailLimit(
        int $userId,
        string $reason,
        string $message,
        string $retryAt,
        string $subject,
        string $fromAddr,
    ): int {
        $reason = mb_substr($reason, 0, 255);
        return (new Undo($this->db))->write($userId, function () use ($userId, $reason, $message, $retryAt, $subject, $fromAddr): int {
            // The same account lock as MailAdmission makes concurrent denials
            // update one aggregate instead of racing two open rows or counts.
            $row = $this->db->one(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
                [$userId, self::KIND_MAIL_LIMIT, $reason]
            );
            $payload = $row !== null ? self::payload($row) : [];
            $count = max(0, (int) ($payload['count'] ?? 0)) + 1;
            $latestSubject = mb_substr(trim($subject), 0, 300);
            $latestFrom = mb_substr(trim($fromAddr), 0, 255);
            $latest = $latestSubject !== ''
                ? ' Latest: “' . $latestSubject . '”' . ($latestFrom !== '' ? ' from ' . $latestFrom : '') . '.'
                : ($latestFrom !== '' ? ' Latest sender: ' . $latestFrom . '.' : '');
            $summary = mb_substr(
                $message . ' ' . $count . ' email' . ($count === 1 ? ' was' : 's were')
                . ' affected. No event was added; the original email remains in your mailbox.' . $latest,
                0,
                1000
            );
            $payload = [
                'reason' => $reason,
                'count' => $count,
                'retryAt' => $retryAt !== '' ? $retryAt : null,
                'latestSubject' => $latestSubject,
                'latestFrom' => $latestFrom,
            ];
            if ($row !== null) {
                $this->db->run(
                    'UPDATE review_items SET summary = ?, payload_json = ?, created_at = ? WHERE id = ?',
                    [$summary, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), Time::nowDb(), (int) $row['id']]
                );
                return (int) $row['id'];
            }
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_MAIL_LIMIT,
                'event_id' => null,
                'source_key' => $reason,
                'title' => 'Email automation limit reached',
                'summary' => $summary,
                'payload_json' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    /**
     * One bounded notice per calendar and capacity reason. Prompt verdicts are
     * fail-open while delayed, so this explains the temporary behavior without
     * turning normal quota deferral into a system failure.
     */
    public function holdModelLimit(
        int $userId,
        string $reason,
        string $message,
        string $retryAt,
        int $calendarId,
        string $calendarName,
        int $batchSize,
        string $operation = 'filter',
    ): int {
        $sourceKey = mb_substr($reason . ':calendar:' . $calendarId, 0, 255);
        return (new Undo($this->db))->write($userId, function () use ($userId, $reason, $message, $retryAt, $calendarId, $calendarName, $batchSize, $sourceKey, $operation): int {
            $row = $this->db->one(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
                [$userId, self::KIND_MODEL_LIMIT, $sourceKey]
            );
            $payload = $row !== null ? self::payload($row) : [];
            $count = max(0, (int) ($payload['count'] ?? 0)) + 1;
            $name = mb_substr(trim($calendarName) ?: 'Subscribed calendar', 0, 300);
            $summary = mb_substr(
                $message . ($operation === 'ranking'
                    ? ' Your events are still available; new scores will be added when capacity is available.'
                    : ' Events from “' . $name . '” remain visible; new or changed events pass through the filter until its queued checks run.')
                . ($retryAt !== '' ? ' Processing will retry automatically.' : ''),
                0,
                1000
            );
            $payload = [
                'operation' => $operation,
                'reason' => mb_substr($reason, 0, 255),
                'count' => $count,
                'retryAt' => $retryAt !== '' ? $retryAt : null,
                'calendarId' => $calendarId,
                'calendarName' => $name,
                'latestBatchSize' => max(0, $batchSize),
            ];
            if ($row !== null) {
                $this->db->run(
                    'UPDATE review_items SET title = ?, summary = ?, payload_json = ?, created_at = ? WHERE id = ?',
                    [$operation === 'ranking' ? 'Event ranking paused' : 'AI filter processing paused', $summary, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), Time::nowDb(), (int) $row['id']]
                );
                return (int) $row['id'];
            }
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_MODEL_LIMIT,
                'event_id' => null,
                'source_key' => $sourceKey,
                'title' => $operation === 'ranking' ? 'Event ranking paused' : 'AI filter processing paused',
                'summary' => $summary,
                'payload_json' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    // ---- Reading -------------------------------------------------------

    /** The settings, as the app uses them (Settings::resolved): it adopts all of them from a Review answer. */
    public function mailSettings(int $userId): array
    {
        $settings = new Settings($this->db);
        return $settings->resolved($userId, $settings->forUser($userId));
    }

    /** @return list<array<string,mixed>> newest first */
    public function listFor(int $userId, bool $openOnly = true, ?int $before = null): array
    {
        $rows = fn(): array => $this->db->all(
            'SELECT * FROM review_items WHERE user_id = ?' . ($openOnly ? " AND status = 'open'" : " AND status <> 'superseded'")
                . ($before !== null ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT 200',
            $before !== null ? [$userId, $before] : [$userId]
        );
        $listed = $rows();
        if ($this->convertHeldDays($userId, $listed) > 0) {
            $listed = $rows();
        }
        return array_map(self::serialize(...), $listed);
    }

    /**
     * A day held before its series (#192) whose series is on the calendar
     * by now, put there by a path that did not convert it, is never shown
     * waiting for the series beside it: it becomes a change to that
     * occurrence as the queue is read, with a note of it in Activity that
     * stands on the series (an undo of the add would strand the day). The
     * add and the Review accept convert as they go, so this finds nothing
     * in the usual run. Returns how many items were converted.
     *
     * @param list<array<string,mixed>> $rows review_items rows
     */
    private function convertHeldDays(int $userId, array $rows): int
    {
        $uids = [];
        foreach ($rows as $row) {
            if ((string) $row['kind'] !== self::KIND_INVITE_NEW || (string) $row['status'] !== 'open') continue;
            $payload = self::payload($row);
            if (isset($payload['occurrence'])) $uids[(string) ($payload['uid'] ?? $row['source_key'])] = (string) $row['source_key'];
        }
        $converted = 0;
        foreach ($uids as $uid => $sourceKey) {
            $series = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL LIMIT 1', [$userId, (string) $uid]);
            if ($series === null) continue;
            $converted += (new Undo($this->db))->write($userId, function () use ($userId, $sourceKey, $series): int {
                $held = [];
                foreach ($this->db->all(
                    "SELECT id, title, payload_json FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                    [$userId, self::KIND_INVITE_NEW, $sourceKey]
                ) as $open) {
                    if (isset(self::payload($open)['occurrence'])) $held[] = $open;
                }
                if ($held === []) return 0;
                $this->reconcilePending($userId, (int) $series, $sourceKey);
                (new Undo($this->db))->record($userId, 'review', (int) $held[0]['id'], 'update', null, null,
                    '"' . mb_substr((string) $held[0]['title'], 0, 200) . '" is on your calendar now: ' . (count($held) === 1
                        ? 'a day of it held in Review is a change to that occurrence'
                        : 'the days of it held in Review are changes to their occurrences'),
                    touches: array_merge(
                        array_map(static fn(array $row): array => [Undo::keyOf('review_items', ['id' => (int) $row['id']]), '*'], $held),
                        [[Undo::keyOf('events', ['id' => (int) $series]), '+']],
                    ));
                return count($held);
            });
        }
        return $converted;
    }

    public function openCount(int $userId, ?string $kind = null): int
    {
        return $kind === null
            ? (int) $this->db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND status = 'open'", [$userId])
            : (int) $this->db->scalar(
                "SELECT COUNT(*) FROM review_items WHERE user_id = ? AND kind = ? AND status = 'open'",
                [$userId, $kind]
            );
    }

    /** @return array<string,mixed> */
    public static function serialize(array $row): array
    {
        $payload = is_array($row['payload_json'] ?? null) ? $row['payload_json'] : (json_decode((string) ($row['payload_json'] ?? ''), true) ?: []);
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'eventId' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
            'title' => (string) $row['title'],
            'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
            'status' => (string) $row['status'],
            'method' => (string) ($payload['method'] ?? ''),
            'from' => (string) ($payload['from'] ?? ''),
            'organizer' => $payload['invite']['organizer'] ?? null,
            'diff' => is_array($payload['diff'] ?? null) ? $payload['diff'] : [],
            'detail' => $payload,
            'createdAt' => Time::dbToIso((string) $row['created_at'], 'UTC'),
            'decidedAt' => !empty($row['decided_at']) ? Time::dbToIso((string) $row['decided_at'], 'UTC') : null,
        ];
    }

    /**
     * Invitations the owner has not answered: real iMIP invitations (method
     * REQUEST, so there is an organizer to reply to) that are still ahead and
     * not cancelled. Tickets and confirmations the markup/LLM tiers turned into
     * events carry an invite block too, but nobody is waiting on a reply to an
     * airline, so they are not decisions and stay out.
     *
     * Filtered in PHP rather than with JSON functions: the set is tiny, and it
     * keeps the query portable.
     *
     * @return list<array<string,mixed>> soonest first
     */
    public function invitationsAwaitingReply(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $rows = $this->db->all(
            "SELECT id, title, start_utc, end_utc, all_day, tzid, location, rrule, invite_json, created_at FROM events
             WHERE user_id = ? AND deleted_at IS NULL AND invite_json IS NOT NULL AND status <> 'cancelled'
               AND recurrence_parent_id IS NULL AND (end_utc > ? OR rrule IS NOT NULL)
             ORDER BY start_utc ASC LIMIT 500",
            [$userId, Time::toDb($now)]
        );
        $out = [];
        foreach ($rows as $row) {
            $invite = is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true);
            if (!is_array($invite) || ($invite['method'] ?? '') !== 'REQUEST' || ($invite['myPartstat'] ?? 'NEEDS-ACTION') !== 'NEEDS-ACTION') {
                continue;
            }
            // Dismissed from Review without answering. An updated invitation
            // stores a fresh invite block and asks again.
            if (!empty($invite['reviewDismissedAt'])) {
                continue;
            }
            // Only what can actually be answered from here (#70); the event
            // itself says why one can't.
            if (Rsvp::kind($invite) !== 'invitation' || Rsvp::blocker($invite, config()) !== null) {
                continue;
            }
            // A series that has run out is not waiting on anyone either.
            // The last date in the event's own zone (audit, 0.9.14).
            if (!empty($row['rrule']) && preg_match('/UNTIL=(\d{8})/', (string) $row['rrule'], $m) === 1 && $m[1] < $now->setTimezone(Time::zone((string) $row['tzid']))->format('Ymd')) {
                continue;
            }
            $tz = Time::zone((string) $row['tzid']);
            $allDay = (int) $row['all_day'] === 1;
            $fmt = static fn(string $db): string => $allDay
                ? Time::fromDb($db)->setTimezone($tz)->format('Y-m-d') . 'T00:00:00+00:00'
                : Time::iso(Time::fromDb($db)->setTimezone($tz));
            $out[] = [
                'eventId' => (int) $row['id'],
                'title' => (string) $row['title'],
                'start' => $fmt((string) $row['start_utc']),
                'end' => $fmt((string) $row['end_utc']),
                'allDay' => $allDay,
                // The event's zone, as an event carries it: the MCP server
                // gives UTC times on the owner's Home clock by it.
                'tzid' => (string) $row['tzid'],
                'recurring' => !empty($row['rrule']),
                'location' => $row['location'] !== null ? (string) $row['location'] : null,
                'organizer' => $invite['organizer'] ?? null,
                'via' => ($invite['via'] ?? 'mail') === 'google' ? 'google' : 'mail',
                'invitedCount' => is_array($invite['attendees'] ?? null) ? count($invite['attendees']) : 0,
                'createdAt' => Time::dbToIso((string) $row['created_at'], 'UTC'),
            ];
        }
        return $out;
    }

    /**
     * What a client needs to open the event an item is about: the same
     * {instanceId, at} pair a reminder notification links with. For a series it
     * names the first occurrence, which is where the series' own details live.
     *
     * @return array{instanceId:string, at:string}|null null when the event is gone
     */
    public function eventLink(int $userId, int $eventId): ?array
    {
        $row = $this->db->one('SELECT id, start_utc, tzid FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$eventId, $userId]);
        if ($row === null) {
            return null;
        }
        $start = Time::fromDb((string) $row['start_utc']);
        return [
            'instanceId' => Recurrence::instanceId((int) $row['id'], $start),
            'at' => Time::iso($start->setTimezone(Time::zone((string) $row['tzid']))),
        ];
    }

    // ---- Deciding ------------------------------------------------------

    /** @return array<string,mixed> the item as it now stands */
    public function dismiss(int $userId, int $id): array
    {
        $row = (new Undo($this->db))->write($userId, function () use ($userId, $id): array {
            $row = $this->requireOpen($userId, $id);
            if (!in_array((string) $row['kind'], [self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE], true)) {
                throw HttpError::notFound('No such invitation review item');
            }
            $this->close((int) $row['id'], 'dismissed');
            $payload = self::payload($row);
            $summary = (string) $row['kind'] === self::KIND_INVITE_NEW
                ? 'Dismissed emailed invitation "' . (string) $row['title'] . '" without adding it'
                : 'Dismissed an emailed ' . (($payload['method'] ?? '') === 'CANCEL' ? 'cancellation of' : 'change to') . ' "' . (string) $row['title'] . '"';
            ActivityContext::with('review', fn() => (new Undo($this->db))->record(
                $userId,
                'event',
                (int) ($row['event_id'] ?? 0),
                'refuse',
                null,
                null,
                $summary,
                ['from' => $payload['from'] ?? null, 'reviewItem' => (int) $row['id']]
            ));
            return $row;
        });
        return self::serialize($this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]) ?? $row);
    }

    /** Dismiss an informational aggregate; it never mutates the calendar. */
    public function dismissMailLimit(int $userId, int $id): array
    {
        $row = $this->requireOpen($userId, $id);
        if ((string) $row['kind'] !== self::KIND_MAIL_LIMIT) {
            throw HttpError::notFound('No such email-limit review item');
        }
        $this->close((int) $row['id'], 'dismissed');
        return self::serialize($this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]) ?? $row);
    }

    /** Dismiss an informational prompt-filter capacity notice. */
    public function dismissModelLimit(int $userId, int $id): array
    {
        $row = $this->requireOpen($userId, $id);
        if ((string) $row['kind'] !== self::KIND_MODEL_LIMIT) {
            throw HttpError::notFound('No such model-limit review item');
        }
        $this->close((int) $row['id'], 'dismissed');
        return self::serialize($this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]) ?? $row);
    }

    /**
     * Apply a held change. Re-checked against the event AS IT IS NOW, not as it
     * was when the mail arrived: the event may have been deleted, or a later
     * change accepted, since. Goes through Events::patch so it gets the same
     * validation, Activity entry and undo as a change made by hand; the invite
     * block (and with it the SEQUENCE watermark, for CANCEL as well as REQUEST:
     * BC-09) is stored in the same transaction.
     *
     * @return array{item:array<string,mixed>, eventId:int}
     */
    public function accept(int $userId, int $id, ?string $approveSender = null, ?RequestAuthority $authority = null): array
    {
        $undo = new Undo($this->db);
        $title = (string) ($this->db->scalar('SELECT title FROM review_items WHERE id = ? AND user_id = ?', [$id, $userId]) ?? '');
        // One entry for the decision, and its undo puts all of it back: the
        // event as it was, the invitation stored on it, a sender approved
        // with it, and the item open in Review again.
        $result = ActivityContext::withRun('review', Ids::ulid(), fn(): array => $undo->write($userId, fn(): array => $undo->group(function () use ($userId, $id, $approveSender, $authority, $undo): array {
            $itemBefore = $this->db->one('SELECT * FROM review_items WHERE id = ? AND user_id = ?', [$id, $userId]);
            // The decided item, and every other version of the invitation
            // held with it, which accepting closes or turns into changes.
            $items = fn(): array => $this->db->all('SELECT * FROM review_items WHERE user_id = ? AND (id = ? OR (source_key = ? AND kind IN (?, ?))) ORDER BY id',
                [$userId, $id, (string) ($itemBefore['source_key'] ?? ''), self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE]);
            $eventRows = fn(int $eventId): array => $eventId > 0 ? $this->db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ?', [$eventId, $eventId]) : [];
            $approved = fn() => (new Settings($this->db))->forUser($userId)['mailApprovedSenders'] ?? null;
            $itemsBefore = $items();
            $eventsBefore = $eventRows((int) ($itemBefore['event_id'] ?? 0));
            $approvedBefore = $approved();
            $out = $this->decide($userId, $id, $approveSender, $authority);
            if (!isset($out['error']) && $itemBefore !== null) {
                $approvedAfter = $approved();
                $undo->record($userId, 'review', $id, 'update',
                    ['review_items' => $itemsBefore, 'events' => $eventsBefore],
                    ['review_items' => $items(), 'events' => $eventRows((int) ($out['eventId'] ?? 0))],
                    null,
                    $approvedAfter !== $approvedBefore ? ['settings' => ['mailApprovedSenders' => [$approvedBefore, $approvedAfter]]] : null);
            }
            return $out;
        }, "Accepted '" . $title . "' from Review")));
        if (isset($result['error'])) {
            throw HttpError::conflict((string) $result['error'], (string) $result['message']);
        }
        $row = $this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]);
        return ['item' => self::serialize($row ?? []), 'eventId' => (int) $result['eventId']] + (!empty($result['removed']) ? ['removed' => true] : []);
    }

    /** Apply the held change (accept's work, inside its write and group). */
    private function decide(int $userId, int $id, ?string $approveSender, ?RequestAuthority $authority): array
    {
        $authority?->assertValid($this->db);
        $row = $this->requireOpen($userId, $id);
        $kind = (string) $row['kind'];
        if (!in_array($kind, [self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE], true)) {
            throw HttpError::notFound('No such invitation review item');
        }
        $payload = self::payload($row);
        $incoming = is_array($payload['invite'] ?? null) ? $payload['invite'] : [];
        if (!empty($incoming['organizer'])) $incoming['organizerTrust'] = 'owner';

        if ($kind === self::KIND_INVITE_NEW) {
            $uid = (string) ($payload['uid'] ?? '');
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
            if ($uid === '' || $fields === []) {
                $this->close((int) $row['id'], 'gone');
                return self::decisionError('review_gone', 'That invitation is incomplete and cannot be added. The item has been closed.');
            }
            $existing = $this->db->scalar(
                'SELECT id FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL LIMIT 1' . $this->forUpdate(),
                [$userId, $uid]
            );
            // A change to one occurrence held before its series (#192) is
            // never made an event of its own: it would pass for the whole
            // series. It waits for the series; once that is on the calendar
            // (added here, automatically, or any other way) it becomes a
            // change to that occurrence, shown before it is applied.
            if (isset($payload['occurrence'])) {
                if ($existing === null) {
                    $cancelsDay = ($payload['method'] ?? '') === 'CANCEL';
                    return self::decisionError('review_waiting', 'This ' . ($cancelsDay ? 'cancels' : 'changes') . ' one occurrence of a repeating event that is not on your calendar.'
                        . ' Add the series first and this becomes a ' . ($cancelsDay ? 'cancellation of' : 'change to') . ' that occurrence, or dismiss it.');
                }
                $this->reconcilePending($userId, (int) $existing, (string) $row['source_key']);
                return (string) $this->db->scalar('SELECT status FROM review_items WHERE id = ?', [(int) $row['id']]) === 'open'
                    ? self::decisionError('review_changed', 'That series is on your calendar now, so this is a change to one of its occurrences. Review the differences and try again.')
                    : self::decisionError('review_stale', 'That series is on your calendar, but this change to one of its occurrences no longer applies to it. The item has been closed.');
            }
            if ($existing !== null) {
                $this->close((int) $row['id'], 'superseded');
                return self::decisionError('review_stale', 'That invitation is already on your calendar. The duplicate item has been closed.');
            }
            if ($approveSender !== null) MailPolicy::approve($this->db, $userId, (string) ($payload['from'] ?? ''), $approveSender);
            $calendarId = MailIngest::ensureInviteCalendar($this->db, $userId);
            // Not answered yet, so a Maybe until the owner accepts.
            $request = ($payload['method'] ?? '') === 'REQUEST';
            if ($request) $incoming['myPartstat'] = 'NEEDS-ACTION';
            $tier = in_array($payload['tier'] ?? 'imip', ['imip', 'markup', 'gcal-link', 'llm'], true) ? ($payload['tier'] ?? 'imip') : 'imip';
            $occurrence = ActivityContext::with('mail:' . $tier . ':review', fn(): array => $this->events->create(
                $userId,
                ($request ? ['status' => 'tentative'] : []) + $fields + ['calendarId' => $calendarId, 'uid' => $uid],
                $authority
            ));
            $eventId = (int) $occurrence['eventId'];
            MailIngest::storeInvite($this->db, $eventId, $incoming, false);
            $this->db->run('UPDATE review_items SET event_id = ? WHERE id = ?', [$eventId, (int) $row['id']]);
            $this->close((int) $row['id'], 'accepted');
            $this->reconcilePending($userId, $eventId, (string) $row['source_key']);
            return ['eventId' => $eventId];
        }

        $eventId = (int) ($row['event_id'] ?? 0);
        $event = $this->db->one(
            'SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL' . $this->forUpdate(),
            [$eventId, $userId]
        );
        if ($event === null) {
            $this->close((int) $row['id'], 'gone');
            return self::decisionError('review_gone', 'That event no longer exists, so there is nothing to change. The item has been closed.');
        }
        $storedInvite = is_string($event['invite_json'] ?? null) ? json_decode((string) $event['invite_json'], true) : ($event['invite_json'] ?? null);
        $storedTrustEstablished = MailIngest::organizerTrustEstablished(is_array($storedInvite) ? $storedInvite : null);
        $occurrence = isset($payload['occurrence']) ? (string) $payload['occurrence'] : null;
        $overrideRow = $occurrence !== null ? self::occurrenceRow($this->db, $eventId, $occurrence) : null;
        // One occurrence is checked against the series' organizer and its
        // own sequence (MailIngest::occurrenceGate).
        [$allowed, $why] = $occurrence !== null
            ? MailIngest::imipMayMutate(MailIngest::occurrenceGate(is_array($storedInvite) ? $storedInvite : null, $overrideRow), $incoming, (string) ($payload['from'] ?? ''), (string) ($overrideRow['status'] ?? 'confirmed'))
            : MailIngest::imipMayMutate(is_array($storedInvite) ? $storedInvite : null, $incoming, (string) ($payload['from'] ?? ''), (string) ($event['status'] ?? 'confirmed'));
        if (!$allowed) {
            $this->close((int) $row['id'], 'superseded');
            return self::decisionError('review_stale', 'This change is out of date (' . $why . '): a newer version of the invitation has already been applied. The item has been closed.');
        }
        if ($occurrence !== null) {
            // One occurrence never changes the whole series (#155): a
            // cancellation skips it, a change becomes that occurrence's own
            // exception, the way an edit to "this occurrence" does.
            $method = (string) ($payload['method'] ?? '');
            $fields = (array) ($payload['fields'] ?? []);
            $cancel = $method === 'CANCEL' || ($fields['status'] ?? null) === 'cancelled';
            $instance = (int) ($event['all_day'] ?? 0) === 1
                ? Time::fromDb($occurrence)->setTimezone(Time::zone((string) $event['tzid']))->format('Y-m-d')
                : Time::iso(Time::fromDb($occurrence));
            $currentDiff = self::occurrenceDiff($event, $occurrence, $cancel ? 'CANCEL' : 'REQUEST', $fields, $overrideRow, Settings::homeTzid($this->db, $userId));
            if ($currentDiff === []) {
                $this->close((int) $row['id'], 'superseded');
                return $cancel
                    ? self::decisionError('review_stale', 'That occurrence is already skipped. The item has been closed.')
                    : self::decisionError('review_stale', 'That occurrence already matches this change. The item has been closed.');
            }
            // The day named may no longer be one of the series' occurrences:
            // the owner moved the series, or changed its rule, since the
            // email arrived (#192). Events refuses it (nothing written yet);
            // the item is closed, with the reason, rather than left to fail
            // the same way on every press.
            $staleOccurrence = fn(): array => self::decisionError('review_stale',
                'That day is no longer an occurrence of this series, which was moved or changed since this email arrived, so there is nothing to change. The item has been closed.');
            if ($cancel) {
                try {
                    $this->events->deleteEvent($userId, $eventId, 'this', $instance, $authority);
                } catch (HttpError $e) {
                    if ($e->errorCode !== 'invalid_recurrence_instance') throw $e;
                    $this->close((int) $row['id'], 'superseded');
                    return $staleOccurrence();
                }
                $this->close((int) $row['id'], 'accepted');
                return ['eventId' => $eventId, 'removed' => false];
            }
            if (!self::sameDiff($currentDiff, (array) ($payload['diff'] ?? []))) {
                $payload['diff'] = $currentDiff;
                $this->db->run(
                    'UPDATE review_items SET summary = ?, payload_json = ? WHERE id = ?',
                    [
                        'That occurrence changed after this email arrived. Review the refreshed differences before applying it.',
                        json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
                        (int) $row['id'],
                    ]
                );
                return self::decisionError('review_changed', 'That occurrence changed after this email arrived. The differences were refreshed; review them and try again.');
            }
            $patch = ['scope' => 'this', 'instanceStart' => $instance];
            foreach (['title', 'start', 'end', 'allDay', 'tzid', 'location', 'description'] as $key) {
                if (array_key_exists($key, $fields)) $patch[$key] = $fields[$key];
            }
            if ($overrideRow !== null && (string) ($overrideRow['status'] ?? '') === 'cancelled') {
                $patch['status'] = 'confirmed';
            }
            try {
                $this->events->patch($userId, $eventId, $patch, $authority);
            } catch (HttpError $e) {
                if ($e->errorCode !== 'invalid_recurrence_instance') throw $e;
                $this->close((int) $row['id'], 'superseded');
                return $staleOccurrence();
            }
            // The occurrence keeps its own sequence watermark; the series'
            // organizer stays the one the owner approved.
            $applied = self::occurrenceRow($this->db, $eventId, $occurrence);
            if ($applied !== null) {
                if ($storedTrustEstablished && is_array($storedInvite) && !empty($storedInvite['organizer'])) {
                    $incoming['organizer'] = $storedInvite['organizer'];
                }
                // Marked as this occurrence's own (MailIngest::occurrenceGate).
                if ($incoming !== []) $incoming['forOccurrence'] = $occurrence;
                MailIngest::storeInvite($this->db, (int) $applied['id'], $incoming === [] ? null : $incoming, true);
            }
            $this->close((int) $row['id'], 'accepted');
            return ['eventId' => $eventId, 'removed' => false];
        }

        $method = (string) ($payload['method'] ?? '');
        $fields = (array) ($payload['fields'] ?? []);
        $currentDiff = self::inviteDiff($event, $method, $fields);
        if ($currentDiff === []) {
            $this->close((int) $row['id'], 'superseded');
            return self::decisionError('review_stale', 'Your calendar already matches this invitation change. The item has been closed.');
        }
        if (!self::sameDiff($currentDiff, (array) ($payload['diff'] ?? []))) {
            // The owner edited the event after this card was prepared. Do
            // not apply a full emailed snapshot against a different state
            // than the differences they reviewed. Refresh the card under
            // this same lock; the next click evaluates the refreshed diff.
            $payload['diff'] = $currentDiff;
            $this->db->run(
                'UPDATE review_items SET title = ?, summary = ?, payload_json = ? WHERE id = ?',
                [
                    mb_substr((string) $event['title'], 0, 300),
                    'Your calendar changed after this email arrived. Review the refreshed differences before applying it.',
                    json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
                    (int) $row['id'],
                ]
            );
            return self::decisionError('review_changed', 'Your event changed after this email arrived. The differences were refreshed; review them and try again.');
        }

        // Once the owner has approved an organizer, later decisions cannot
        // silently rebind it. A legacy auto-created invitation has no such
        // marker; the first explicit owner decision establishes the anchor.
        if ($storedTrustEstablished && is_array($storedInvite) && !empty($storedInvite['organizer'])) {
            $incoming['organizer'] = $storedInvite['organizer'];
        }
        $patch = $method === 'CANCEL' ? ['status' => 'cancelled'] : $fields;
        if ($method !== 'CANCEL' && (string) ($event['status'] ?? '') === 'cancelled') {
            $patch['status'] = 'confirmed';
        }
        if (!empty($event['rrule']) && empty($event['recurrence_parent_id'])) {
            $patch['scope'] = 'all';
        }
        $this->events->patch($userId, $eventId, $patch, $authority);
        MailIngest::storeInvite($this->db, $eventId, $incoming === [] ? null : $incoming, true);
        $this->close((int) $row['id'], 'accepted');
        // The owner's choice (#132): an accepted cancellation removes the
        // event right away, as an ordinary delete with Undo, instead of
        // leaving it struck through.
        $removed = false;
        if ($method === 'CANCEL' && (new Settings($this->db))->forUser($userId)['removeAcceptedCancellations'] === true) {
            $this->events->deleteEvent($userId, $eventId, null, null, $authority);
            $removed = true;
        }
        $this->reconcilePending($userId, $eventId, (string) $row['source_key']);
        return ['eventId' => $eventId, 'removed' => $removed];
    }

    /**
     * Whether two invitation diffs say the same thing. A stored one comes back
     * from MySQL's JSON column with its keys reordered (to, from, field,
     * label), so comparing the arrays strictly always differed and every
     * Accept refreshed the card and refused again. Pure; unit-tested.
     *
     * @param list<array<string,mixed>> $a
     * @param list<array<string,mixed>> $b
     */
    public static function sameDiff(array $a, array $b): bool
    {
        $canon = static fn(array $rows): array => array_map(
            static fn($r): array => is_array($r)
                ? [(string) ($r['field'] ?? ''), (string) ($r['label'] ?? ''), $r['from'] ?? null, $r['to'] ?? null]
                : [],
            array_values($rows)
        );
        return $canon($a) === $canon($b);
    }

    /**
     * Take an unanswered invitation out of Review without answering it: no
     * reply goes to the organizer, and the event keeps its Accept / Maybe /
     * Decline. Recorded in Activity.
     *
     * @return array{eventId:int}
     */
    public function dismissReply(int $userId, int $eventId): array
    {
        return ActivityContext::with('review', fn(): array => (new Undo($this->db))->write($userId, function () use ($userId, $eventId): array {
            $row = $this->db->one(
                'SELECT id, title, invite_json FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL' . $this->forUpdate(),
                [$eventId, $userId]
            );
            $invite = $row !== null ? (is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true)) : null;
            if (!is_array($invite) || ($invite['myPartstat'] ?? 'NEEDS-ACTION') !== 'NEEDS-ACTION') {
                throw HttpError::notFound('No unanswered invitation on this event');
            }
            $invite['reviewDismissedAt'] = Time::iso(Time::nowUtc());
            $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), $eventId]);
            (new Undo($this->db))->record($userId, 'event', $eventId, 'refuse', null, null,
                'Dismissed the reply reminder for "' . (string) $row['title'] . '" without answering', touches: [[Undo::keyOf('events', ['id' => $eventId]), 'invite_json']]);
            return ['eventId' => $eventId];
        }));
    }

    /** Close open items whose event is gone, so the queue never offers a decision about nothing. */
    public function closeOrphans(int $userId): int
    {
        return $this->db->run(
            "UPDATE review_items SET status = 'gone', decided_at = ?
             WHERE user_id = ? AND status = 'open' AND event_id IS NOT NULL
               AND event_id NOT IN (SELECT id FROM events WHERE user_id = ? AND deleted_at IS NULL)",
            [Time::nowDb(), $userId, $userId]
        )->rowCount();
    }

    // ---- internals -----------------------------------------------------

    /** @return array<string,mixed> */
    private function requireOpen(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM review_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such review item');
        }
        if ((string) $row['status'] !== 'open') {
            throw HttpError::conflict('review_decided', 'This item was already ' . (string) $row['status'] . '.');
        }
        return $row;
    }

    private function close(int $id, string $status): void
    {
        $this->db->run('UPDATE review_items SET status = ?, decided_at = ? WHERE id = ?', [$status, Time::nowDb(), $id]);
    }

    /** Only an applied decision can make a competing revision stale. */
    private function reconcilePending(int $userId, int $eventId, string $sourceKey): void
    {
        $event = $this->db->one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if ($event === null) return;
        $stored = json_decode((string) ($event['invite_json'] ?? '{}'), true) ?: [];
        foreach ($this->db->all("SELECT * FROM review_items WHERE user_id = ? AND source_key = ? AND kind IN (?, ?) AND status = 'open'",
            [$userId, $sourceKey, self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE]) as $pending) {
            $payload = self::payload($pending);
            $incoming = (array) ($payload['invite'] ?? []);
            $method = ($payload['method'] ?? '') === 'CANCEL' ? 'CANCEL' : 'REQUEST';
            $occurrence = isset($payload['occurrence']) ? (string) $payload['occurrence'] : null;
            if ($occurrence !== null) {
                // One occurrence is numbered apart from its series (#155):
                // judged as holdInviteChange and decide judge it, against its
                // own sequence and the day as the calendar shows it, so a
                // series change at a higher number doesn't close it.
                $override = self::occurrenceRow($this->db, $eventId, $occurrence);
                [$allowed] = MailIngest::imipMayMutate(MailIngest::occurrenceGate($stored, $override), $incoming, (string) ($payload['from'] ?? ''), (string) ($override['status'] ?? 'confirmed'));
                $diff = self::occurrenceDiff($event, $occurrence, $method, (array) ($payload['fields'] ?? []), $override, Settings::homeTzid($this->db, $userId));
            } else {
                [$allowed] = MailIngest::imipMayMutate($stored, $incoming, (string) ($payload['from'] ?? ''), (string) $event['status']);
                $diff = self::inviteDiff($event, $method, (array) ($payload['fields'] ?? []));
            }
            if (!$allowed || $diff === [] || $event['deleted_at'] !== null) {
                $this->close((int) $pending['id'], 'superseded');
            } elseif ($pending['kind'] === self::KIND_INVITE_NEW) {
                // It arrived before the first version was added. It is now a
                // change, not a second event or a silently discarded revision.
                // One about a single occurrence (#192) is a change to that
                // day, worded and applied as holdInviteChange holds one.
                $payload['method'] = $method;
                $payload['diff'] = $diff;
                if ($occurrence !== null) {
                    $who = (string) ($incoming['organizer']['name'] ?? '') ?: (string) ($incoming['organizer']['email'] ?? '') ?: (string) ($payload['from'] ?? '');
                    $day = self::occurrenceLabel($event, $occurrence, Settings::homeTzid($this->db, $userId));
                    $changed = implode(', ', array_map(static fn(array $d): string => strtolower($d['label']), $diff));
                    $summary = $method === 'CANCEL'
                        ? ($who !== '' ? $who : 'The organizer') . " cancelled the $day occurrence of the series you added. Accepting skips just that one."
                        : ($who !== '' ? $who : 'The organizer') . " changed the $day occurrence of the series you added: $changed. Accepting changes just that one.";
                } else {
                    $summary = 'Another emailed version is waiting. Compare it with the event you added.';
                }
                $this->db->update('review_items', ['kind' => self::KIND_INVITE_CHANGE, 'event_id' => $eventId, 'summary' => $summary,
                    'payload_json' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)], 'id = ?', [(int) $pending['id']]);
            }
        }
    }

    /** Self-contained notice: receipt pruning must not erase an unread message's diagnosis. */
    public function holdMailError(int $userId, string $key, string $from, string $subject, string $reason): int
    {
        return (new Undo($this->db))->write($userId, function () use ($userId, $key, $from, $subject, $reason): int {
            $key = hash('sha256', $key);
            $existing = $this->db->scalar('SELECT id FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ?', [$userId, self::KIND_MAIL_ERROR, $key]);
            if ($existing !== null) return (int) $existing;
            // Junk mail can't bury real invitations under its own notices
            // (review of 0.9.87): past a screenful, one notice counts the rest.
            if ($this->openCount($userId, self::KIND_MAIL_ERROR) >= self::MAIL_ERROR_NOTICES) {
                return $this->holdMailLimit($userId, 'mail_unreadable', 'More emails could not be read than Review lists one by one. Check the mailbox for them.', '', $subject, $from);
            }
            $from = mb_substr($from, 0, 255);
            $subject = mb_substr($subject, 0, 500);
            return $this->db->insert('review_items', [
                'user_id' => $userId, 'kind' => self::KIND_MAIL_ERROR, 'source_key' => $key, 'event_id' => null,
                'title' => "An email couldn't be read",
                'summary' => mb_substr('From: ' . ($from ?: 'unavailable') . '. Subject: ' . ($subject ?: 'unavailable')
                    . '. The original email remains in your mailbox. Check it there and check your calendar; add any missing event by hand or forward a corrected copy.', 0, 1000),
                'payload_json' => json_encode(['from' => $from, 'subject' => $subject, 'reason' => mb_substr($reason, 0, 500)], JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        });
    }

    public function dismissMailError(int $userId, int $id): array
    {
        $row = $this->requireOpen($userId, $id);
        if ($row['kind'] !== self::KIND_MAIL_ERROR) throw HttpError::notFound('No such unreadable email notice');
        $this->close($id, 'dismissed');
        return self::serialize($this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]) ?? $row);
    }

    /** @return array<string,mixed> */
    private static function payload(array $row): array
    {
        return is_array($row['payload_json'] ?? null) ? $row['payload_json'] : (json_decode((string) ($row['payload_json'] ?? ''), true) ?: []);
    }

    private function pendingInvitationCount(int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM review_items WHERE user_id = ? AND status = 'open' AND kind IN (?, ?)",
            [$userId, self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE]
        );
    }

    private function forUpdate(): string
    {
        return $this->db->forUpdate();
    }

    /** @return array{error:string,message:string} */
    private static function decisionError(string $code, string $message): array
    {
        return ['error' => $code, 'message' => $message];
    }
}
