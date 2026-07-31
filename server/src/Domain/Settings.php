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
        'weekStart' => 'sun',
        'timeFormat' => '12',
        'defaultCalendarId' => null,
        'theme' => 'system',
        'nlParseMode' => 'smart',
    ];
    private const VIEWS = ['month', 'multiweek', 'week', 'day', 'agenda'];

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
                'defaultCalendarId' => self::calendarId($value),
                default => throw HttpError::badRequest("Unknown setting '$key'", 'unknown_setting'),
            };
        }
        return $out;
    }

    private static function enum(string $key, mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw HttpError::badRequest("$key must be one of: " . implode('|', $allowed));
        }
        return $value;
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
