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
 * Events domain (same validation, same activity entry, same undo), and a newer
 * change for the same meeting replaces an older open one rather than stacking.
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

    private const PREVIEW_CHARS = 400;

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

    // ---- Holding -------------------------------------------------------

    /**
     * Hold a first-time emailed invitation without creating an event. Distinct
     * candidates for the same sender-controlled UID remain visible: an
     * unauthenticated high SEQUENCE must not hide the genuine invitation. An
     * exact transport replay is coalesced.
     *
     * @param array<string,mixed> $fields Events::create payload without calendarId/uid
     * @param array<string,mixed>|null $invite organizer, attendees and sequence
     */
    public function holdNewInvitation(int $userId, string $uid, array $fields, ?array $invite, string $fromAddr): int
    {
        $invite = is_array($invite) ? $invite : [];
        $sequence = (int) ($invite['sequence'] ?? 0);
        $candidateKey = hash('sha256', json_encode([$uid, $fromAddr, $fields, $invite], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '');
        $who = (string) ($invite['organizer']['name'] ?? '') ?: (string) ($invite['organizer']['email'] ?? '') ?: $fromAddr;
        $summary = ($who !== '' ? "Invitation from $who." : 'New emailed invitation.') . ' It has not been added to your calendar.';
        $sourceKey = mb_substr($uid, 0, 255);

        return $this->db->tx(function () use ($userId, $uid, $fields, $invite, $fromAddr, $sequence, $candidateKey, $summary, $sourceKey): int {
            $this->lockAccount($userId);
            foreach ($this->db->all(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC",
                [$userId, self::KIND_INVITE_NEW, $sourceKey]
            ) as $open) {
                if ((string) (self::payload($open)['candidateKey'] ?? '') === $candidateKey) {
                    return (int) $open['id'];
                }
            }
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_INVITE_NEW,
                'event_id' => null,
                'source_key' => $sourceKey,
                'title' => mb_substr((string) ($fields['title'] ?? '(untitled)'), 0, 300),
                'summary' => mb_substr($summary, 0, 1000),
                'payload_json' => json_encode([
                    'method' => 'REQUEST',
                    'uid' => $uid,
                    'from' => $fromAddr,
                    'fields' => $fields,
                    'invite' => $invite,
                    'sequence' => $sequence,
                    'candidateKey' => $candidateKey,
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    /**
     * Hold an emailed change to an existing invitation. Once the owner has
     * established organizer trust, an older sequence cannot displace a newer
     * one and the newest candidate replaces the prior open decision. Before
     * that trust exists, sequence and organizer are both sender claims: keep
     * distinct candidates side by side so a forged high sequence cannot hide
     * the genuine change. Exact transport replays are coalesced in both cases.
     * Returns null when there is nothing to decide or the bounded queue is full.
     *
     * @param array<string,mixed> $event  the existing events row
     * @param array<string,mixed> $fields Events::patch payload (ignored for CANCEL)
     * @param array<string,mixed>|null $invite the incoming invite block (organizer, attendees, sequence)
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
    ): ?int {
        $diff = self::inviteDiff($event, $method, $fields);
        if ($diff === []) {
            return null;
        }
        $invite = is_array($invite) ? $invite : [];
        $candidateKey = hash('sha256', json_encode(
            [$uid, $method, $fromAddr, $method === 'CANCEL' ? null : $fields, $invite],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        ) ?: '');
        $title = (string) ($event['title'] ?? '(untitled)');
        $who = (string) ($invite['organizer']['name'] ?? '') ?: (string) ($invite['organizer']['email'] ?? '') ?: $fromAddr;
        $summary = $method === 'CANCEL'
            ? ($who !== '' ? "$who cancelled this." : 'The organizer cancelled this.')
            : ($who !== '' ? "$who changed: " : 'Changed: ') . implode(', ', array_map(static fn(array $d): string => strtolower($d['label']), $diff)) . '.';

        return $this->db->tx(function () use ($userId, $event, $uid, $method, $fields, $invite, $fromAddr, $diff, $title, $summary, $candidateKey, $sequenceAuthoritative): ?int {
            $this->lockAccount($userId);
            $sourceKey = mb_substr($uid, 0, 255);
            $incomingSequence = (int) ($invite['sequence'] ?? 0);
            $open = $this->db->all(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC",
                [$userId, self::KIND_INVITE_CHANGE, $sourceKey]
            );
            foreach ($open as $pending) {
                if ((string) (self::payload($pending)['candidateKey'] ?? '') === $candidateKey) {
                    return (int) $pending['id'];
                }
            }
            if ($sequenceAuthoritative) {
                foreach ($open as $pending) {
                    $pendingSequence = (int) (self::payload($pending)['invite']['sequence'] ?? 0);
                    if ($pendingSequence > $incomingSequence) {
                        return null;
                    }
                }
            }

            // An authoritative replacement consumes no new slot. Every
            // parallel legacy candidate does, as does the first candidate for
            // a trusted UID. The users-row lock makes the count and insert one
            // account-wide operation under concurrent mail workers.
            if ((!$sequenceAuthoritative || $open === []) && $this->pendingInvitationCount($userId) >= Limits::get('MAIL_PENDING_INVITATIONS')) {
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
            if ($sequenceAuthoritative) {
                $this->db->run(
                    "UPDATE review_items SET status = 'superseded', decided_at = ? WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                    [Time::nowDb(), $userId, self::KIND_INVITE_CHANGE, $sourceKey]
                );
            }
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_INVITE_CHANGE,
                'event_id' => (int) $event['id'],
                'source_key' => $sourceKey,
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
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
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
        return $this->db->tx(function () use ($userId, $reason, $message, $retryAt, $subject, $fromAddr): int {
            // The same account lock as MailAdmission makes concurrent denials
            // update one aggregate instead of racing two open rows or counts.
            $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $this->db->scalar('SELECT id FROM users WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''), [$userId]);
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
    ): int {
        $sourceKey = mb_substr($reason . ':calendar:' . $calendarId, 0, 255);
        return $this->db->tx(function () use ($userId, $reason, $message, $retryAt, $calendarId, $calendarName, $batchSize, $sourceKey): int {
            $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $this->db->scalar('SELECT id FROM users WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''), [$userId]);
            $row = $this->db->one(
                "SELECT * FROM review_items WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
                [$userId, self::KIND_MODEL_LIMIT, $sourceKey]
            );
            $payload = $row !== null ? self::payload($row) : [];
            $count = max(0, (int) ($payload['count'] ?? 0)) + 1;
            $name = mb_substr(trim($calendarName) ?: 'Subscribed calendar', 0, 300);
            $summary = mb_substr(
                $message . ' Events from “' . $name . '” remain visible; new or changed events pass through the filter until its queued checks run.'
                . ($retryAt !== '' ? ' Processing will retry automatically.' : ''),
                0,
                1000
            );
            $payload = [
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
                    ['AI filter processing paused', $summary, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), Time::nowDb(), (int) $row['id']]
                );
                return (int) $row['id'];
            }
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_MODEL_LIMIT,
                'event_id' => null,
                'source_key' => $sourceKey,
                'title' => 'AI filter processing paused',
                'summary' => $summary,
                'payload_json' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    // ---- Reading -------------------------------------------------------

    /** @return list<array<string,mixed>> newest first */
    public function listFor(int $userId, bool $openOnly = true): array
    {
        $rows = $this->db->all(
            'SELECT * FROM review_items WHERE user_id = ?' . ($openOnly ? " AND status = 'open'" : " AND status <> 'superseded'") . ' ORDER BY id DESC LIMIT 200',
            [$userId]
        );
        return array_map(self::serialize(...), $rows);
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
        $row = $this->db->tx(function () use ($userId, $id): array {
            $this->lockAccount($userId);
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
    public function accept(int $userId, int $id): array
    {
        $result = ActivityContext::withRun('review', Ids::ulid(), fn(): array => $this->db->tx(function () use ($userId, $id): array {
            $this->lockAccount($userId);
            $row = $this->requireOpen($userId, $id);
            $kind = (string) $row['kind'];
            if (!in_array($kind, [self::KIND_INVITE_NEW, self::KIND_INVITE_CHANGE], true)) {
                throw HttpError::notFound('No such invitation review item');
            }
            $payload = self::payload($row);
            $incoming = is_array($payload['invite'] ?? null) ? $payload['invite'] : [];
            $incoming['organizerTrust'] = 'owner';

            if ($kind === self::KIND_INVITE_NEW) {
                $uid = (string) ($payload['uid'] ?? '');
                $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
                if ($uid === '' || (string) ($payload['method'] ?? '') !== 'REQUEST' || $fields === []) {
                    $this->close((int) $row['id'], 'gone');
                    return self::decisionError('review_gone', 'That invitation is incomplete and cannot be added. The item has been closed.');
                }
                $existing = $this->db->scalar(
                    'SELECT id FROM events WHERE user_id = ? AND uid = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL LIMIT 1' . $this->forUpdate(),
                    [$userId, $uid]
                );
                if ($existing !== null) {
                    $this->close((int) $row['id'], 'superseded');
                    return self::decisionError('review_stale', 'That invitation is already on your calendar. The duplicate item has been closed.');
                }
                $calendarId = MailIngest::ensureInviteCalendar($this->db, $userId);
                $occurrence = ActivityContext::with('mail:imip:review', fn(): array => $this->events->create(
                    $userId,
                    $fields + ['calendarId' => $calendarId, 'uid' => $uid]
                ));
                $eventId = (int) $occurrence['eventId'];
                MailIngest::storeInvite($this->db, $eventId, $incoming, false);
                $this->db->run('UPDATE review_items SET event_id = ? WHERE id = ?', [$eventId, (int) $row['id']]);
                $this->close((int) $row['id'], 'accepted');
                $this->db->run(
                    "UPDATE review_items SET status = 'superseded', decided_at = ? WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                    [Time::nowDb(), $userId, self::KIND_INVITE_NEW, (string) $row['source_key']]
                );
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
            // Rows held before sequenceAuthoritative was introduced have no
            // marker. Classify those from the event while it is locked: an
            // untrusted legacy anchor means this was necessarily a competing
            // pre-anchor claim and choosing it must close its siblings too.
            $wasParallelLegacyCandidate = array_key_exists('sequenceAuthoritative', $payload)
                ? $payload['sequenceAuthoritative'] === false
                : !$storedTrustEstablished;
            [$allowed, $why] = MailIngest::imipMayMutate(is_array($storedInvite) ? $storedInvite : null, $incoming, (string) ($payload['from'] ?? ''), (string) ($event['status'] ?? 'confirmed'));
            if (!$allowed) {
                $this->close((int) $row['id'], 'superseded');
                return self::decisionError('review_stale', 'This change is out of date (' . $why . '): a newer version of the invitation has already been applied. The item has been closed.');
            }

            $method = (string) ($payload['method'] ?? '');
            $fields = (array) ($payload['fields'] ?? []);
            $currentDiff = self::inviteDiff($event, $method, $fields);
            if ($currentDiff === []) {
                $this->close((int) $row['id'], 'superseded');
                return self::decisionError('review_stale', 'Your calendar already matches this invitation change. The item has been closed.');
            }
            if ($currentDiff !== (array) ($payload['diff'] ?? [])) {
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
            $this->events->patch($userId, $eventId, $patch);
            MailIngest::storeInvite($this->db, $eventId, $incoming === [] ? null : $incoming, true);
            $this->close((int) $row['id'], 'accepted');
            if ($wasParallelLegacyCandidate) {
                // This explicit owner choice establishes the organizer anchor.
                // Every competing pre-anchor claim for the UID is now stale.
                $this->db->run(
                    "UPDATE review_items SET status = 'superseded', decided_at = ? WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                    [Time::nowDb(), $userId, self::KIND_INVITE_CHANGE, (string) $row['source_key']]
                );
            }
            return ['eventId' => $eventId];
        }));
        if (isset($result['error'])) {
            throw HttpError::conflict((string) $result['error'], (string) $result['message']);
        }
        $row = $this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]);
        return ['item' => self::serialize($row ?? []), 'eventId' => (int) $result['eventId']];
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

    /** @return array<string,mixed> */
    private static function payload(array $row): array
    {
        return is_array($row['payload_json'] ?? null) ? $row['payload_json'] : (json_decode((string) ($row['payload_json'] ?? ''), true) ?: []);
    }

    private function lockAccount(int $userId): void
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($this->db->scalar('SELECT id FROM users WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''), [$userId]) === null) {
            throw new \RuntimeException('Review decision could not lock its account');
        }
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
        return (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /** @return array{error:string,message:string} */
    private static function decisionError(string $code, string $message): array
    {
        return ['error' => $code, 'message' => $message];
    }
}
