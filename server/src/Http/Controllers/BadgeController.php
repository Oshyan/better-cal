<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\AppBadge;
use BetterCal\Domain\Events;
use BetterCal\Domain\Settings;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * GET /badge?tz=Area/City -> {show, count}: what the installed app's icon
 * shows (#170). count is null when the setting is none. The zone is the
 * device's (the service worker sends it), falling back to the Home zone.
 */
final class BadgeController
{
    public function __construct(
        private readonly Db $db,
        private readonly Settings $settings,
        private readonly Events $events,
        private readonly ReviewController $review,
    ) {
    }

    public function show(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $show = (string) ($this->settings->forUser($userId)['appBadge'] ?? 'none');
        if (!in_array($show, AppBadge::MODES, true) || $show === 'none') {
            return Response::json(['show' => 'none', 'count' => null]);
        }
        if ($show === 'review') {
            return Response::json(['show' => $show, 'count' => $this->review->total($userId)]);
        }
        $now = Time::nowUtc();
        [$start, $end] = AppBadge::dayBounds($now, $this->zone($req->q('tz'), $userId));
        $visible = array_map('intval', array_column($this->db->all(
            'SELECT id FROM calendars WHERE user_id = ? AND visible = 1',
            [$userId]
        ), 'id'));
        $occurrences = $visible === [] ? [] : $this->events->window($userId, $start, $end, $visible, null, false);
        return Response::json(['show' => $show, 'count' => AppBadge::countEvents($occurrences, $show === 'remaining', $now, [$start, $end])]);
    }

    private function zone(?string $tz, int $userId): \DateTimeZone
    {
        foreach ([$tz, Settings::homeTzid($this->db, $userId)] as $candidate) {
            if (!is_string($candidate) || $candidate === '') continue;
            try {
                return new \DateTimeZone(Time::normalizeTzid($candidate));
            } catch (\Throwable) {
                continue;
            }
        }
        return new \DateTimeZone('UTC');
    }
}
