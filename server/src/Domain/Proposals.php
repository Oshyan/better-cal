<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

/**
 * The C11 seam (docs/plugins/prd-v1.md): a plugin PROPOSES, the user decides.
 *
 * The rule that makes proposals worth having: generating one never touches the
 * calendar. A planner can re-run every hour, replace its own suggestions by
 * source key, and change its mind, and the user's calendar is untouched until
 * they press Accept. Acceptance then materializes the whole plan atomically —
 * a Trip container plus its member events, linked — under one run id, so
 * "undo that" reverses all of it as a single act rather than leaving half a
 * trip behind.
 *
 * The plan is data, not code: the host executes it through the ordinary Events
 * domain, so plugin proposals get the same validation, activity attribution,
 * and undo snapshots as anything a user creates by hand.
 */
final class Proposals
{
    private const MAX_EVENTS_PER_PLAN = 50;

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly Trips $trips,
    ) {
    }

    /**
     * Validate a plan's shape. Pure; unit-tested. Returns problems, empty = ok.
     *
     * Plan shape:
     *   {
     *     trip?: {title, start, end, calendarId?},
     *     events: [{title, start, end?, allDay?, location?, description?, calendarId?}]
     *   }
     *
     * @return list<string>
     */
    public static function planErrors(mixed $plan): array
    {
        $errs = [];
        if (!is_array($plan)) {
            return ['plan must be an object'];
        }
        $events = $plan['events'] ?? null;
        if (!is_array($events) || $events === []) {
            $errs[] = 'plan.events must be a non-empty array';
            $events = [];
        }
        if (count($events) > self::MAX_EVENTS_PER_PLAN) {
            $errs[] = 'plan.events exceeds ' . self::MAX_EVENTS_PER_PLAN;
        }
        foreach (array_values($events) as $i => $ev) {
            if (!is_array($ev)) {
                $errs[] = "events[$i] must be an object";
                continue;
            }
            if (!is_string($ev['title'] ?? null) || trim((string) $ev['title']) === '') {
                $errs[] = "events[$i].title is required";
            }
            if (!is_string($ev['start'] ?? null) || trim((string) $ev['start']) === '') {
                $errs[] = "events[$i].start is required";
            }
        }
        if (isset($plan['trip'])) {
            $t = $plan['trip'];
            if (!is_array($t)) {
                $errs[] = 'plan.trip must be an object';
            } else {
                if (!is_string($t['title'] ?? null) || trim((string) $t['title']) === '') {
                    $errs[] = 'plan.trip.title is required';
                }
                if (!is_string($t['start'] ?? null) || !is_string($t['end'] ?? null)) {
                    $errs[] = 'plan.trip needs start and end';
                }
            }
        }
        return $errs;
    }

    /**
     * Create or replace a proposal by (plugin, sourceKey). Replacing resets an
     * open proposal in place; an already-decided one is left alone so a
     * re-run cannot silently un-accept the user's decision.
     */
    public function upsert(int $userId, string $pluginId, array $in): array
    {
        $sourceKey = mb_substr((string) ($in['sourceKey'] ?? ''), 0, 160);
        if ($sourceKey === '') {
            throw HttpError::badRequest('sourceKey is required');
        }
        $errs = self::planErrors($in['plan'] ?? null);
        if ($errs !== []) {
            throw HttpError::badRequest('Invalid plan: ' . implode('; ', $errs));
        }
        $existing = $this->db->one(
            'SELECT * FROM plugin_proposals WHERE plugin_id = ? AND source_key = ?',
            [$pluginId, $sourceKey]
        );
        $fields = [
            'title' => mb_substr(Sanitize::toText((string) ($in['title'] ?? '')), 0, 300),
            'summary' => isset($in['summary']) ? mb_substr(Sanitize::toText((string) $in['summary']), 0, 1000) : null,
            'rationale_html' => isset($in['rationaleHtml']) ? Sanitize::html((string) $in['rationaleHtml']) : null,
            'plan_json' => json_encode($in['plan'], JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        if ($existing === null) {
            $id = $this->db->insert('plugin_proposals', $fields + [
                'plugin_id' => $pluginId,
                'user_id' => $userId,
                'source_key' => $sourceKey,
            ]);
            return $this->serialize($this->db->one('SELECT * FROM plugin_proposals WHERE id = ?', [$id]));
        }
        if ((string) $existing['status'] !== 'open') {
            return $this->serialize($existing); // decided: leave it alone
        }
        $this->db->update('plugin_proposals', $fields, 'id = ?', [(int) $existing['id']]);
        return $this->serialize($this->db->one('SELECT * FROM plugin_proposals WHERE id = ?', [(int) $existing['id']]));
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $userId, ?string $status = 'open'): array
    {
        $sql = 'SELECT * FROM plugin_proposals WHERE user_id = ?';
        $params = [$userId];
        if ($status !== null && $status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        return array_map($this->serialize(...), $this->db->all($sql, $params));
    }

    /**
     * Retract a still-open proposal by source key. Decided proposals are left
     * alone: an accepted plan is on the calendar and a rejection is the user's
     * answer, neither of which a plugin may erase.
     *
     * Without this a plugin had no way to say "never mind", so every shift in
     * its answer stranded an open proposal the user had to dismiss by hand.
     */
    public function withdraw(int $userId, string $pluginId, string $sourceKey): bool
    {
        return $this->db->run(
            "DELETE FROM plugin_proposals
             WHERE user_id = ? AND plugin_id = ? AND source_key = ? AND status = 'open'",
            [$userId, $pluginId, mb_substr($sourceKey, 0, 160)]
        )->rowCount() > 0;
    }

    public function reject(int $userId, int $id): array
    {
        $p = $this->require($userId, $id);
        if ((string) $p['status'] !== 'open') {
            throw HttpError::badRequest('Already ' . $p['status']);
        }
        $this->db->update('plugin_proposals', ['status' => 'rejected', 'decided_at' => Time::nowDb()], 'id = ?', [$id]);
        return $this->serialize($this->db->one('SELECT * FROM plugin_proposals WHERE id = ?', [$id]));
    }

    /**
     * Materialize a plan: the Trip container first, then its events, then the
     * links — all under one run id and one transaction, so acceptance is
     * all-or-nothing and reverses as a single group.
     */
    public function accept(int $userId, int $id): array
    {
        $p = $this->require($userId, $id);
        if ((string) $p['status'] !== 'open') {
            throw HttpError::badRequest('Already ' . $p['status']);
        }
        $plan = json_decode((string) $p['plan_json'], true);
        $errs = self::planErrors($plan);
        if ($errs !== []) {
            throw HttpError::badRequest('Stored plan is no longer valid: ' . implode('; ', $errs));
        }
        $runId = Ids::ulid();
        $pluginId = (string) $p['plugin_id'];
        $defaultCal = $this->defaultCalendarId($userId);

        $created = ActivityContext::withRun('plugin:' . $pluginId, $runId, function () use ($userId, $plan, $defaultCal): array {
            $tripId = null;
            if (isset($plan['trip'])) {
                $t = $plan['trip'];
                $trip = $this->events->create($userId, [
                    'calendarId' => (int) ($t['calendarId'] ?? $defaultCal),
                    'title' => (string) $t['title'],
                    'start' => (string) $t['start'],
                    'end' => (string) $t['end'],
                    'allDay' => true,
                    'isContainer' => true,
                    'description' => isset($t['description']) ? (string) $t['description'] : null,
                ]);
                $tripId = (int) $trip['eventId'];
            }
            $eventIds = [];
            foreach ($plan['events'] as $ev) {
                $row = $this->events->create($userId, [
                    'calendarId' => (int) ($ev['calendarId'] ?? $defaultCal),
                    'title' => (string) $ev['title'],
                    'start' => (string) $ev['start'],
                    'end' => isset($ev['end']) ? (string) $ev['end'] : (string) $ev['start'],
                    'allDay' => (bool) ($ev['allDay'] ?? false),
                    'location' => isset($ev['location']) ? (string) $ev['location'] : null,
                    'description' => isset($ev['description']) ? (string) $ev['description'] : null,
                ]);
                $eventIds[] = (int) $row['eventId'];
                if ($tripId !== null) {
                    $this->trips->attach($userId, $tripId, (int) $row['eventId']);
                }
            }
            return ['tripId' => $tripId, 'eventIds' => $eventIds];
        });

        $this->db->update('plugin_proposals', [
            'status' => 'accepted',
            'decided_at' => Time::nowDb(),
            'accepted_run_id' => $runId,
        ], 'id = ?', [$id]);

        return [
            'proposal' => $this->serialize($this->db->one('SELECT * FROM plugin_proposals WHERE id = ?', [$id])),
            'created' => $created,
            'runId' => $runId,
        ];
    }

    /** Reverse an acceptance: undo the whole run, reopen the proposal. */
    public function undoAccept(int $userId, int $id): array
    {
        $p = $this->require($userId, $id);
        if ((string) $p['status'] !== 'accepted' || $p['accepted_run_id'] === null) {
            throw HttpError::badRequest('This proposal has not been accepted');
        }
        $result = (new Undo($this->db))->undoRun($userId, (string) $p['accepted_run_id']);
        $this->db->update('plugin_proposals', [
            'status' => 'open',
            'decided_at' => null,
            'accepted_run_id' => null,
        ], 'id = ?', [$id]);
        return $result;
    }

    private function defaultCalendarId(int $userId): int
    {
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $settings = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $preferred = isset($settings['defaultCalendarId']) ? (int) $settings['defaultCalendarId'] : 0;
        if ($preferred > 0) {
            $ok = $this->db->one("SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'local'", [$preferred, $userId]);
            if ($ok !== null) {
                return $preferred;
            }
        }
        $row = $this->db->one("SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY position, id LIMIT 1", [$userId]);
        if ($row === null) {
            throw HttpError::badRequest('No writable calendar to accept this proposal into');
        }
        return (int) $row['id'];
    }

    private function require(int $userId, int $id): array
    {
        $p = $this->db->one('SELECT * FROM plugin_proposals WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($p === null) {
            throw HttpError::notFound('No such proposal');
        }
        return $p;
    }

    private function serialize(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'pluginId' => (string) $r['plugin_id'],
            'sourceKey' => (string) $r['source_key'],
            'title' => (string) $r['title'],
            'summary' => $r['summary'] !== null ? (string) $r['summary'] : null,
            'rationaleHtml' => $r['rationale_html'] !== null ? (string) $r['rationale_html'] : null,
            'plan' => json_decode((string) $r['plan_json'], true),
            'status' => (string) $r['status'],
            'createdAt' => Time::iso(Time::fromDb((string) $r['created_at'])),
            'decidedAt' => $r['decided_at'] !== null ? Time::iso(Time::fromDb((string) $r['decided_at'])) : null,
        ];
    }
}
