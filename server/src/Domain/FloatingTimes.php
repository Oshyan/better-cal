<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Support\Time;

/**
 * Floating times on Events (#167, Floating): the viewer whose clock a
 * floating row shows on, and a request's instants moved from that clock to
 * the row's own before anything reads them. Part of Events.
 *
 * The viewer is the device a request comes from (the X-Device-Tz header the
 * app sends with every call); without one, the Home zone, where a floating
 * row's stored clock already is.
 */
trait FloatingTimes
{
    private ?\DateTimeZone $viewer = null;

    /** The device zone a request names (X-Device-Tz), or null for none or an unknown zone. */
    public function viewFrom(?string $tzid): void
    {
        $this->viewer = null;
        if ($tzid === null || trim($tzid) === '') {
            return;
        }
        try {
            $this->viewer = new \DateTimeZone(trim($tzid));
        } catch (\Throwable) {
            $this->viewer = null;
        }
    }

    /** The device zone the request named, or '' for none (the window's ETag names it). */
    public function viewerName(): string
    {
        return $this->viewer?->getName() ?? '';
    }

    /** viewerZone() when any of these rows floats, else null: nothing to move, nothing read. */
    private function viewerFor(array $rows, int $userId): ?\DateTimeZone
    {
        foreach ($rows as $row) {
            if (Floating::is($row)) {
                return $this->viewerZone($userId);
            }
        }
        return null;
    }

    /** The clock floating rows show on for this user: the request's device, else Home. */
    public function viewerZone(int $userId): \DateTimeZone
    {
        return $this->viewer ?? Time::zone($this->userTzid($userId));
    }

    /**
     * A request's instants on the row's own clock: for a floating row (or
     * one the request makes floating, or a new timed event on a calendar
     * whose new timed events float), start and end are read as wall clocks
     * on the device's clock (or the request's tzid where no device is named)
     * and moved to the row's (Floating::stored); an occurrence key
     * (instanceStart) of a floating row likewise. A typed clock is read in
     * the row's zone, and a tzid sent is dropped, since a floating event
     * has no zone of its own; a new one is written in the Home zone.
     * Called once, where a request comes in (the controllers, quick add);
     * everything after it reads stored instants.
     *
     * @param ?int $id the event, or null for a new one
     */
    public function fromViewer(int $userId, ?int $id, array $in): array
    {
        $row = $id !== null ? $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $userId]) : null;
        if ($id !== null && $row === null) {
            return $in;
        }
        $home = $this->userTzid($userId);
        $allDay = array_key_exists('allDay', $in)
            ? filter_var($in['allDay'], FILTER_VALIDATE_BOOL)
            : $row !== null && (int) $row['all_day'] === 1;
        if ($row === null && !array_key_exists('floating', $in) && !$allDay && self::calendarFloats((int) ($in['calendarId'] ?? 0), $userId)) {
            // The calendar's "new timed events float" presets the flag (only that).
            $in['floating'] = true;
        }
        $wasFloating = $row !== null && Floating::is($row);
        $floats = !$allDay && (array_key_exists('floating', $in) ? filter_var($in['floating'], FILTER_VALIDATE_BOOL) : $wasFloating);
        $from = $this->viewer ?? Time::zone(isset($in['tzid']) ? (string) $in['tzid'] : $home);
        $own = Time::zone($row === null || (int) $row['all_day'] === 1 ? $home : (string) $row['tzid']);
        $move = static function (mixed $iso) use ($from, $own): mixed {
            if (!is_string($iso) || $iso === '') {
                return $iso;
            }
            try {
                return Time::iso(Floating::stored(Time::parseIso($iso, $from->getName()), $own, $from)->setTimezone($own));
            } catch (\InvalidArgumentException) {
                return $iso; // the endpoint refuses it in its own words
            }
        };
        if ($wasFloating && isset($in['instanceStart'])) {
            $in['instanceStart'] = $move($in['instanceStart']);
        }
        if (!$floats) {
            return $in;
        }
        foreach (['start', 'end'] as $k) {
            if (isset($in[$k])) {
                $in[$k] = $move($in[$k]);
            }
        }
        if (is_array($in['typed'] ?? null)) {
            $in['typed']['tz'] = $own->getName();
        }
        if ($row === null) {
            $in['tzid'] = $own->getName();
        } else {
            unset($in['tzid']);
        }
        return $in;
    }

    /** Does this calendar's setting make new timed events float? */
    private function calendarFloats(int $calendarId, int $userId): bool
    {
        $raw = $this->db->scalar('SELECT settings_json FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        return Calendars::floatTimedFor(is_string($raw) ? $raw : null);
    }

    /**
     * The floating column a write sets: never on an all-day event, and never
     * on an invitation or an event with guests, whose people see it at one
     * moment wherever they are.
     *
     * @return array{floating?:int}
     */
    private static function floatingColumn(array $current, array $in, bool $allDay): array
    {
        if (!array_key_exists('floating', $in)) {
            return $allDay && (int) ($current['floating'] ?? 0) === 1 ? ['floating' => 0] : [];
        }
        $floats = filter_var($in['floating'], FILTER_VALIDATE_BOOL) && !$allDay;
        if ($floats && ($current['invite_json'] ?? null) !== null) {
            throw new HttpError('floating_refused', 'An invitation can\'t float: everyone invited sees it at the same moment.', 422);
        }
        // Written only when it changes, so a pinned event's write is the
        // same write it was before floating times.
        return $floats === ((int) ($current['floating'] ?? 0) === 1) ? [] : ['floating' => $floats ? 1 : 0];
    }
}
