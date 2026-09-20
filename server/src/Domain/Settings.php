<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * User settings stored in users.settings_json. The server stores and
 * validates; enforcement is client-side except nlParseMode, which QuickAdd
 * reads to decide whether the LLM parse runs (see QuickAdd::useLlm).
 * Reads always return the stored values merged over DEFAULTS.
 */
final class Settings
{
    public const DEFAULTS = [
        'defaultView' => 'month',
        'defaultViewId' => null, // a saved view that "Default view" (0) applies; null = the built-in reset
        'weekStart' => 'sun',
        'timeFormat' => '12',
        'defaultCalendarId' => null,
        'theme' => 'system',
        'nlParseMode' => 'smart',
        // Reminder delivery channel: push | email | both | push-fallback
        // (push, with email only when no device looks reachable). Enforced
        // server-side by the reminder scan (Reminders::channelPlan).
        'notifyChannel' => 'push',
        // Where reminder/test emails are sent; null = the account email
        // (which is also the SMTP sender mailbox and may not be read).
        'notifyEmail' => null,
        // Overview layout for the month slot; null = unset (client applies
        // its device default: 3day on mobile, month on desktop).
        'overviewMode' => null,
        'folderVisibility' => [],
        // Sidebar list density: hide unchecked calendars/people from the list
        // itself. Purely a display preference; it never changes visibility.
        'sidebarActiveOnly' => false,
        'pluginHidden' => [],
        'tz' => null,
        // Global default reminders; effective-reminder resolution falls back
        // to these when neither the event nor its calendar overrides them.
        'reminderTimed' => [['minutes' => 10]],
        'reminderAllDay' => [['daysBefore' => 1, 'time' => '18:00']],
        // Home location: bias point for place search (nullable pair) plus a
        // display label for the Settings page.
        'homeLat' => null,
        'homeLng' => null,
        'homeLabel' => null,
        // MapTiler raster style for the event detail mini-map (used only when
        // BETTERCAL_MAPTILER_KEY is configured; OSM tiles otherwise).
        'mapStyle' => 'streets-v2',
    ];
    private const VIEWS = ['month', 'multiweek', 'week', 'day', 'agenda'];
    private const MAP_STYLES = ['streets-v2', 'dataviz', 'outdoor-v2', 'bright-v2'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed> stored settings merged over defaults */
    public function forUser(int $userId): array
    {
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $stored = is_string($raw) ? json_decode($raw, true) : $raw;
        return self::withDefaults(is_array($stored) ? $stored : []);
    }

    /** Validate + merge the given keys into the stored settings; returns the merged result. */
    public function patch(int $userId, array $in): array
    {
        $updates = self::validate($in);
        if (isset($updates['defaultCalendarId'])) {
            $owned = $this->db->scalar(
                "SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'local'",
                [$updates['defaultCalendarId'], $userId]
            );
            if ($owned === null) {
                throw HttpError::badRequest('defaultCalendarId must reference one of your local calendars');
            }
        }
        if (isset($updates['defaultViewId'])) {
            $owned = $this->db->scalar('SELECT id FROM saved_views WHERE id = ? AND user_id = ?', [$updates['defaultViewId'], $userId]);
            if ($owned === null) {
                throw HttpError::badRequest('defaultViewId must reference one of your saved views');
            }
        }
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        $merged = array_merge(is_array($stored) ? $stored : [], $updates);
        // Persist only known keys so stale/renamed keys never accumulate.
        $merged = array_intersect_key($merged, self::DEFAULTS);
        $this->db->update('users', ['settings_json' => json_encode($merged)], 'id = ?', [$userId]);
        return self::withDefaults($merged);
    }

    // ---- Pure helpers (unit-tested, no DB) -----------------------------

    /** @return array<string,mixed> known keys only, defaults filled in */
    /**
     * The user's IANA timezone. The browser has always known it; nothing ever
     * told the SERVER, which left worker-side code (plugins especially) with
     * no way to build a correct local time. The client posts it at boot.
     */
    private static function tzid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw HttpError::badRequest('tz must be an IANA timezone name');
        }
        try {
            new \DateTimeZone($value);
        } catch (\Throwable) {
            throw HttpError::badRequest('Unknown timezone: ' . $value);
        }
        return $value;
    }

    /** {pluginId: bool} — which plugins' overlay bands are hidden. */
    private static function pluginHidden(mixed $value): array
    {
        if (!is_array($value)) {
            throw HttpError::badRequest('pluginHidden must be an object');
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z][a-z0-9-]{1,63}$/', $k) === 1) {
                $out[$k] = (bool) $v;
            }
        }
        return $out;
    }

    public static function withDefaults(array $stored): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $out[$key] = $stored[$key];
            }
        }
        return $out;
    }

    /**
     * Validate a settings patch. Unknown keys are rejected; known keys are
     * normalized (timeFormat accepts 12/24 as number or string). Ownership of
     * defaultCalendarId is checked separately in patch().
     *
     * @return array<string,mixed> normalized subset
     */
    public static function validate(array $in): array
    {
        $out = [];
        foreach ($in as $key => $value) {
            $out[$key] = match ($key) {
                'defaultView' => self::enum($key, $value, self::VIEWS),
                'weekStart' => self::enum($key, $value, ['mon', 'sun']),
                'timeFormat' => self::enum($key, is_scalar($value) ? (string) $value : $value, ['12', '24']),
                'theme' => self::enum($key, $value, ['system', 'light', 'dark']),
                'nlParseMode' => self::enum($key, $value, ['always', 'smart', 'never']),
                'notifyChannel' => self::enum($key, $value, ['push', 'email', 'both', 'push-fallback']),
                'notifyEmail' => self::email($key, $value),
                'overviewMode' => self::enum($key, $value, ['month', '3day']),
                'defaultCalendarId' => self::calendarId($value),
                'defaultViewId' => self::positiveIntOrNull($key, $value),
                'folderVisibility' => self::folderVisibility($value),
                'sidebarActiveOnly' => self::bool($key, $value),
                'pluginHidden' => self::pluginHidden($value),
                'tz' => self::tzid($value),
                'reminderTimed' => Reminders::validateTimedList($value),
                'reminderAllDay' => Reminders::validateAllDayList($value),
                'homeLat' => self::coordinate($key, $value, 90.0),
                'homeLng' => self::coordinate($key, $value, 180.0),
                'homeLabel' => self::label($key, $value),
                'mapStyle' => self::enum($key, $value, self::MAP_STYLES),
                default => throw HttpError::badRequest("Unknown setting '$key'", 'unknown_setting'),
            };
        }
        return $out;
    }

    /**
     * Effective destination for reminder/test emails: the notifyEmail setting
     * when set, else the account email. $settings is a withDefaults() result.
     */
    public static function notifyDestination(array $settings, string $accountEmail): string
    {
        $to = $settings['notifyEmail'] ?? null;
        return is_string($to) && $to !== '' ? $to : $accountEmail;
    }

    /** Nullable email address (notification destination); null or blank clears. */
    private static function email(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw HttpError::badRequest("$key must be an email address or null");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpError::badRequest("$key must be a valid email address or null");
        }
        return $value;
    }

    /** Nullable coordinate bounded at +-$bound (90 for lat, 180 for lng). */
    private static function coordinate(string $key, mixed $value, float $bound): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || abs((float) $value) > $bound) {
            throw HttpError::badRequest("$key must be a number between -$bound and $bound, or null");
        }
        return (float) $value;
    }

    /** Nullable short display string (home location label). */
    private static function label(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw HttpError::badRequest("$key must be a string or null");
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 200);
    }

    private static function bool(string $key, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw HttpError::badRequest("$key must be true or false");
        }
        return $value;
    }

    private static function enum(string $key, mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw HttpError::badRequest("$key must be one of: " . implode('|', $allowed));
        }
        return $value;
    }

    /**
     * Per-folder visibility state (GH issue #6): map of folderId (string key)
     * to {mode: all|none|custom, custom: [calendarId, ...]}. Client-owned
     * semantics; the server only validates shape and bounds.
     *
     * @return array<string, array{mode:string, custom:list<int>}>
     */
    private static function folderVisibility(mixed $value): array
    {
        if (!is_array($value)) {
            throw HttpError::badRequest('folderVisibility must be an object');
        }
        if (count($value) > 200) {
            throw HttpError::badRequest('folderVisibility has too many entries');
        }
        $out = [];
        foreach ($value as $folderId => $entry) {
            if (!is_array($entry)) {
                throw HttpError::badRequest('folderVisibility entries must be objects');
            }
            $mode = $entry['mode'] ?? null;
            if (!is_string($mode) || !in_array($mode, ['all', 'none', 'custom'], true)) {
                throw HttpError::badRequest('folderVisibility mode must be all|none|custom');
            }
            $custom = $entry['custom'] ?? [];
            if (!is_array($custom) || count($custom) > 500) {
                throw HttpError::badRequest('folderVisibility custom must be a list of calendar ids');
            }
            $ids = [];
            foreach ($custom as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    throw HttpError::badRequest('folderVisibility custom must contain integer calendar ids');
                }
                $ids[] = (int) $id;
            }
            $out[(string) $folderId] = ['mode' => $mode, 'custom' => $ids];
        }
        return $out;
    }

    private static function positiveIntOrNull(string $key, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || (int) $value <= 0) {
            throw HttpError::badRequest("$key must be a positive integer or null");
        }
        return (int) $value;
    }

    private static function calendarId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || (int) $value <= 0) {
            throw HttpError::badRequest('defaultCalendarId must be a positive integer or null');
        }
        return (int) $value;
    }
}
