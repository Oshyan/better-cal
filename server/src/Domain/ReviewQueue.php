<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
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
 * The Review PAGE also lists plugin proposals and invitations awaiting an RSVP
 * (ReviewController assembles the three); only held changes are stored here,
 * because the other two already have a home.
 */
final class ReviewQueue
{
    public const KIND_INVITE_CHANGE = 'invite_change';

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
     * Hold an emailed change to an existing invitation. Replaces any older open
     * change for the same meeting (the organizer moved it twice; only the latest
     * is a decision). Returns the item id, or null when there is nothing to
     * decide (the message changes nothing visible).
     *
     * @param array<string,mixed> $event  the existing events row
     * @param array<string,mixed> $fields Events::patch payload (ignored for CANCEL)
     * @param array<string,mixed>|null $invite the incoming invite block (organizer, attendees, sequence)
     */
    public function holdInviteChange(int $userId, array $event, string $uid, string $method, array $fields, ?array $invite, string $fromAddr): ?int
    {
        $diff = self::inviteDiff($event, $method, $fields);
        if ($diff === []) {
            return null;
        }
        $title = (string) ($event['title'] ?? '(untitled)');
        $who = (string) ($invite['organizer']['name'] ?? '') ?: (string) ($invite['organizer']['email'] ?? '') ?: $fromAddr;
        $summary = $method === 'CANCEL'
            ? ($who !== '' ? "$who cancelled this." : 'The organizer cancelled this.')
            : ($who !== '' ? "$who changed: " : 'Changed: ') . implode(', ', array_map(static fn(array $d): string => strtolower($d['label']), $diff)) . '.';

        return $this->db->tx(function () use ($userId, $event, $uid, $method, $fields, $invite, $fromAddr, $diff, $title, $summary): int {
            $this->db->run(
                "UPDATE review_items SET status = 'superseded', decided_at = ? WHERE user_id = ? AND kind = ? AND source_key = ? AND status = 'open'",
                [Time::nowDb(), $userId, self::KIND_INVITE_CHANGE, mb_substr($uid, 0, 255)]
            );
            return $this->db->insert('review_items', [
                'user_id' => $userId,
                'kind' => self::KIND_INVITE_CHANGE,
                'event_id' => (int) $event['id'],
                'source_key' => mb_substr($uid, 0, 255),
                'title' => mb_substr($title, 0, 300),
                'summary' => mb_substr($summary, 0, 1000),
                'payload_json' => json_encode([
                    'method' => $method,
                    'uid' => $uid,
                    'from' => $fromAddr,
                    'fields' => $method === 'CANCEL' ? null : $fields,
                    'invite' => $invite,
                    'diff' => $diff,
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
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

    public function openCount(int $userId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND status = 'open'", [$userId]);
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
            // A series that has run out is not waiting on anyone either.
            if (!empty($row['rrule']) && preg_match('/UNTIL=(\d{8})/', (string) $row['rrule'], $m) === 1 && $m[1] < $now->format('Ymd')) {
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
        $row = $this->requireOpen($userId, $id);
        $this->close((int) $row['id'], 'dismissed');
        // Dismissing is a decision worth a trace: "I saw the organizer's change
        // and kept my version" is exactly what someone looks for later.
        ActivityContext::with('review', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            (int) ($row['event_id'] ?? 0),
            'refuse',
            null,
            null,
            'Dismissed an emailed ' . (self::payload($row)['method'] === 'CANCEL' ? 'cancellation of' : 'change to') . ' "' . (string) $row['title'] . '"',
            ['from' => self::payload($row)['from'] ?? null, 'reviewItem' => (int) $row['id']]
        ));
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
        $row = $this->requireOpen($userId, $id);
        $payload = self::payload($row);
        $eventId = (int) ($row['event_id'] ?? 0);
        $event = $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$eventId, $userId]);
        if ($event === null) {
            $this->close((int) $row['id'], 'gone');
            throw HttpError::conflict('review_gone', 'That event no longer exists, so there is nothing to change. The item has been closed.');
        }
        $storedInvite = is_string($event['invite_json'] ?? null) ? json_decode((string) $event['invite_json'], true) : ($event['invite_json'] ?? null);
        $incoming = is_array($payload['invite'] ?? null) ? $payload['invite'] : [];
        [$allowed, $why] = MailIngest::imipMayMutate(is_array($storedInvite) ? $storedInvite : null, $incoming, (string) ($payload['from'] ?? ''), (string) ($event['status'] ?? 'confirmed'));
        if (!$allowed) {
            $this->close((int) $row['id'], 'superseded');
            throw HttpError::conflict('review_stale', 'This change is out of date (' . $why . '): a newer version of the invitation has already been applied. The item has been closed.');
        }

        // The organizer the event was bound to stays bound. The incoming
        // ORGANIZER was only checked against the bound one OR the (spoofable)
        // envelope From, so storing it wholesale let a forged message
        // rebind the invitation to the attacker (scan 2026-09-23, F23).
        if (is_array($storedInvite) && !empty($storedInvite['organizer']) && $incoming !== []) {
            $incoming['organizer'] = $storedInvite['organizer'];
        }

        $method = (string) ($payload['method'] ?? '');
        $patch = $method === 'CANCEL' ? ['status' => 'cancelled'] : (array) ($payload['fields'] ?? []);
        if ($method !== 'CANCEL' && (string) ($event['status'] ?? '') === 'cancelled') {
            $patch['status'] = 'confirmed'; // the organizer re-issued a meeting they had cancelled
        }
        if (!empty($event['rrule']) && empty($event['recurrence_parent_id'])) {
            $patch['scope'] = 'all'; // an organizer's update describes the whole series
        }
        ActivityContext::withRun('review', Ids::ulid(), function () use ($userId, $eventId, $patch, $incoming, $row): void {
            $this->db->tx(function () use ($userId, $eventId, $patch, $incoming, $row): void {
                $this->events->patch($userId, $eventId, $patch);
                MailIngest::storeInvite($this->db, $eventId, $incoming === [] ? null : $incoming, true);
                $this->close((int) $row['id'], 'accepted');
            });
        });
        return ['item' => self::serialize($this->db->one('SELECT * FROM review_items WHERE id = ?', [$id]) ?? $row), 'eventId' => $eventId];
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
}
