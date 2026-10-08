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
    private const REVIEW_TOKEN_CONTEXT = 'better-cal/proposal-review/v1';

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
        return $this->db->tx(function () use ($userId, $pluginId, $sourceKey, $in): array {
            // Every proposal lifecycle mutation takes the same account lock.
            // That makes upsert-versus-accept and double decisions serial even
            // before the proposal row exists.
            $this->lockAccount($userId);
            $existing = $this->db->one(
                'SELECT * FROM plugin_proposals WHERE user_id = ? AND plugin_id = ? AND source_key = ?' . $this->forUpdate(),
                [$userId, $pluginId, $sourceKey]
            );
            if ($existing !== null && (string) $existing['status'] !== 'open') {
                return $this->serialize($existing); // decided: leave it alone
            }

            // Store explicit, owner-local destinations. A later default change
            // cannot silently redirect a proposal the owner already reviewed.
            $plan = $this->normalizePlan($userId, $in['plan']);
            $fields = [
                'title' => mb_substr(Sanitize::toText((string) ($in['title'] ?? '')), 0, 300),
                'summary' => isset($in['summary']) ? mb_substr(Sanitize::toText((string) $in['summary']), 0, 1000) : null,
                'rationale_html' => isset($in['rationaleHtml']) ? Sanitize::html((string) $in['rationaleHtml']) : null,
                'plan_json' => json_encode($plan, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ];
            if ($existing === null) {
                $id = $this->db->insert('plugin_proposals', $fields + [
                    'plugin_id' => $pluginId,
                    'user_id' => $userId,
                    'source_key' => $sourceKey,
                ]);
            } else {
                $id = (int) $existing['id'];
                $changed = $this->db->update('plugin_proposals', $fields, "id = ? AND status = 'open'", [$id]);
                if ($changed === 0) {
                    $current = $this->require($userId, $id, true);
                    if ((string) $current['status'] !== 'open') {
                        return $this->serialize($current);
                    }
                }
            }
            return $this->serialize($this->require($userId, $id, true));
        });
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
        return array_map(fn(array $row): array => $this->serialize($row), $this->db->all($sql, $params));
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
        return $this->db->tx(function () use ($userId, $pluginId, $sourceKey): bool {
            $this->lockAccount($userId);
            return $this->db->run(
                "DELETE FROM plugin_proposals
                 WHERE user_id = ? AND plugin_id = ? AND source_key = ? AND status = 'open'",
                [$userId, $pluginId, mb_substr($sourceKey, 0, 160)]
            )->rowCount() > 0;
        });
    }

    public function reject(int $userId, int $id, string $reviewToken): array
    {
        return $this->db->tx(function () use ($userId, $id, $reviewToken): array {
            $this->lockAccount($userId);
            $p = $this->require($userId, $id, true);
            if ((string) $p['status'] !== 'open') {
                throw HttpError::conflict('proposal_decided', 'This proposal was already ' . (string) $p['status'] . '.');
            }
            $this->assertReviewToken($p, $reviewToken);
            $this->db->update('plugin_proposals', ['status' => 'rejected', 'decided_at' => Time::nowDb()], "id = ? AND status = 'open'", [$id]);
            return $this->serialize($this->require($userId, $id, true));
        });
    }

    /**
     * Materialize a plan: the Trip container first, then its events, then the
     * links — all under one run id and one transaction, so acceptance is
     * all-or-nothing and reverses as a single group.
     */
    public function accept(int $userId, int $id, string $reviewToken): array
    {
        $runId = Ids::ulid();
        $result = $this->db->tx(function () use ($userId, $id, $reviewToken, $runId): array {
            $this->lockAccount($userId);
            $p = $this->require($userId, $id, true);
            if ((string) $p['status'] !== 'open') {
                throw HttpError::conflict('proposal_decided', 'This proposal was already ' . (string) $p['status'] . '.');
            }
            $review = $this->assertReviewToken($p, $reviewToken, true);
            if (!$review['acceptAllowed']) {
                throw HttpError::conflict('proposal_target_invalid', (string) $review['acceptError']);
            }
            $plan = $review['plan'];
            $pluginId = (string) $p['plugin_id'];

            $created = ActivityContext::withRun('plugin:' . $pluginId, $runId, function () use ($userId, $plan): array {
                $tripId = null;
                if (isset($plan['trip'])) {
                    $t = $plan['trip'];
                    $trip = $this->events->create($userId, [
                        'calendarId' => (int) $t['calendarId'],
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
                        'calendarId' => (int) $ev['calendarId'],
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

            $changed = $this->db->update('plugin_proposals', [
                'status' => 'accepted',
                'decided_at' => Time::nowDb(),
                'accepted_run_id' => $runId,
            ], "id = ? AND status = 'open'", [$id]);
            if ($changed !== 1) {
                throw HttpError::conflict('proposal_decided', 'This proposal was decided while it was being accepted.');
            }
            return ['created' => $created];
        });

        return [
            'proposal' => $this->serialize($this->require($userId, $id)),
            'created' => $result['created'],
            'runId' => $runId,
        ];
    }

    /** Reverse an acceptance: undo the whole run, reopen the proposal. */
    public function undoAccept(int $userId, int $id): array
    {
        return $this->db->tx(function () use ($userId, $id): array {
            $this->lockAccount($userId);
            $p = $this->require($userId, $id, true);
            if ((string) $p['status'] !== 'accepted' || $p['accepted_run_id'] === null) {
                throw HttpError::conflict('proposal_not_accepted', 'This proposal has not been accepted.');
            }
            try {
                $result = (new Undo($this->db))->undoRun($userId, (string) $p['accepted_run_id'], true);
            } catch (\Throwable) {
                throw HttpError::conflict(
                    'proposal_undo_incomplete',
                    'This proposal can no longer be completely undone. Nothing was changed; remove any events manually if needed.'
                );
            }
            if ($result['skipped'] !== 0) {
                throw HttpError::conflict('proposal_undo_incomplete', 'This proposal could not be completely undone. Nothing was changed.');
            }
            $this->db->update('plugin_proposals', [
                'status' => 'open',
                'decided_at' => null,
                'accepted_run_id' => null,
            ], "id = ? AND status = 'accepted'", [$id]);
            return $result;
        });
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

    private function require(int $userId, int $id, bool $forUpdate = false): array
    {
        $p = $this->db->one(
            'SELECT * FROM plugin_proposals WHERE id = ? AND user_id = ?' . ($forUpdate ? $this->forUpdate() : ''),
            [$id, $userId]
        );
        if ($p === null) {
            throw HttpError::notFound('No such proposal');
        }
        return $p;
    }

    private function lockAccount(int $userId): void
    {
        $this->db->scalar('SELECT id FROM users WHERE id = ?' . $this->forUpdate(), [$userId]);
    }

    private function forUpdate(): string
    {
        return $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /** Persist an effective local destination for every materialized item. */
    private function normalizePlan(int $userId, array $plan): array
    {
        if (is_array($plan['events'] ?? null)) {
            $plan['events'] = array_values($plan['events']);
        }
        $resolved = $this->resolvePlan($userId, $plan);
        if (!$resolved['acceptAllowed']) {
            throw HttpError::badRequest((string) $resolved['acceptError'], 'proposal_local_calendar_required');
        }
        return $resolved['plan'];
    }

    /**
     * Resolve destinations without writing. Legacy rows can be rendered even
     * when their target is now unavailable; acceptance then fails closed.
     *
     * @return array{plan:array,destinations:array,acceptAllowed:bool,acceptError:?string}
     */
    private function resolvePlan(int $userId, array $plan, bool $forUpdate = false): array
    {
        $defaultId = null;
        $needsDefault = (isset($plan['trip']) && is_array($plan['trip']) && !array_key_exists('calendarId', $plan['trip']));
        foreach ((array) ($plan['events'] ?? []) as $ev) {
            if (is_array($ev) && !array_key_exists('calendarId', $ev)) {
                $needsDefault = true;
                break;
            }
        }
        if ($needsDefault) {
            try {
                $defaultId = $this->defaultCalendarId($userId);
            } catch (HttpError) {
                $defaultId = null;
            }
        }

        $targetIds = [];
        $tripId = null;
        $eventIds = [];
        if (isset($plan['trip']) && is_array($plan['trip'])) {
            $tripId = $this->targetId($plan['trip'], $defaultId);
            if ($tripId !== null) {
                $targetIds[$tripId] = true;
            }
        }
        foreach ((array) ($plan['events'] ?? []) as $ev) {
            $calendarId = is_array($ev) ? $this->targetId($ev, $defaultId) : null;
            $eventIds[] = $calendarId;
            if ($calendarId !== null) {
                $targetIds[$calendarId] = true;
            }
        }

        $rows = [];
        if ($targetIds !== []) {
            $ids = array_keys($targetIds);
            sort($ids, SORT_NUMERIC);
            [$in, $params] = Db::in($ids);
            foreach ($this->db->all(
                "SELECT id, name, kind FROM calendars WHERE user_id = ? AND id IN $in ORDER BY id" . ($forUpdate ? $this->forUpdate() : ''),
                [$userId, ...$params]
            ) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }

        $allowed = true;
        $destination = static function (?int $calendarId) use (&$allowed, $rows): array {
            $row = $calendarId !== null ? ($rows[$calendarId] ?? null) : null;
            $ok = $row !== null && (string) $row['kind'] === 'local';
            if (!$ok) {
                $allowed = false;
            }
            return [
                'calendarId' => $calendarId,
                'calendarName' => $row !== null ? (string) $row['name'] : 'Unavailable calendar',
                'allowed' => $ok,
            ];
        };

        $tripDestination = null;
        if (isset($plan['trip']) && is_array($plan['trip'])) {
            $tripDestination = $destination($tripId);
            if ($tripId !== null) {
                $plan['trip']['calendarId'] = $tripId;
            }
        }
        $eventDestinations = [];
        foreach ($eventIds as $i => $calendarId) {
            $eventDestinations[] = $destination($calendarId);
            if ($calendarId !== null && isset($plan['events'][$i]) && is_array($plan['events'][$i])) {
                $plan['events'][$i]['calendarId'] = $calendarId;
            }
        }

        return [
            'plan' => $plan,
            'destinations' => ['trip' => $tripDestination, 'events' => $eventDestinations],
            'acceptAllowed' => $allowed,
            'acceptError' => $allowed ? null : 'Proposal events can be added only to an available local calendar. Ask the plugin to create a new proposal with a local destination.',
        ];
    }

    private function targetId(array $item, ?int $defaultId): ?int
    {
        if (!array_key_exists('calendarId', $item)) {
            return $defaultId;
        }
        $raw = $item['calendarId'];
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $id = (int) $raw;
            return $id > 0 ? $id : null;
        }
        return null;
    }

    /** @return array{plan:array,destinations:array,acceptAllowed:bool,acceptError:?string,reviewToken:string} */
    private function reviewState(array $r, bool $forUpdate = false): array
    {
        $plan = json_decode((string) $r['plan_json'], true);
        if (!is_array($plan)) {
            $plan = [];
        }
        if (is_array($plan['events'] ?? null)) {
            $plan['events'] = array_values($plan['events']);
        }
        $resolved = $this->resolvePlan((int) $r['user_id'], $plan, $forUpdate);
        $planProblems = self::planErrors($plan);
        if ($planProblems !== []) {
            $resolved['acceptAllowed'] = false;
            $resolved['acceptError'] = 'This stored proposal is incomplete and cannot be accepted. Ask the plugin to create it again.';
        }
        $tokenInput = [
            self::REVIEW_TOKEN_CONTEXT,
            (int) $r['id'],
            (string) $r['plugin_id'],
            (string) $r['source_key'],
            (string) $r['title'],
            $r['summary'] !== null ? (string) $r['summary'] : null,
            $r['rationale_html'] !== null ? (string) $r['rationale_html'] : null,
            $resolved['plan'],
            $resolved['destinations'],
            $resolved['acceptAllowed'],
        ];
        $resolved['reviewToken'] = hash(
            'sha256',
            json_encode($tokenInput, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: ''
        );
        return $resolved;
    }

    /** @return array{plan:array,destinations:array,acceptAllowed:bool,acceptError:?string,reviewToken:string} */
    private function assertReviewToken(array $p, string $provided, bool $forUpdate = false): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
            throw HttpError::badRequest('reviewToken is required', 'proposal_review_token_required');
        }
        $review = $this->reviewState($p, $forUpdate);
        if (!hash_equals($review['reviewToken'], $provided)) {
            throw HttpError::conflict('proposal_changed', 'This proposal changed after you reviewed it. Review the updated plan and try again.');
        }
        return $review;
    }

    private function serialize(array $r): array
    {
        $review = $this->reviewState($r);
        return [
            'id' => (int) $r['id'],
            'pluginId' => (string) $r['plugin_id'],
            'sourceKey' => (string) $r['source_key'],
            'title' => (string) $r['title'],
            'summary' => $r['summary'] !== null ? (string) $r['summary'] : null,
            'rationaleHtml' => $r['rationale_html'] !== null ? (string) $r['rationale_html'] : null,
            'plan' => $review['plan'],
            'destinations' => $review['destinations'],
            'acceptAllowed' => $review['acceptAllowed'],
            'acceptError' => $review['acceptError'],
            'reviewToken' => $review['reviewToken'],
            'status' => (string) $r['status'],
            'createdAt' => Time::iso(Time::fromDb((string) $r['created_at'])),
            'decidedAt' => $r['decided_at'] !== null ? Time::iso(Time::fromDb((string) $r['decided_at'])) : null,
        ];
    }
}
