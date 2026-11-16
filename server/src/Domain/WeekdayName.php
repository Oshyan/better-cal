<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * What quick add reads as a weekday's name: the full name or one of its
 * usual short forms ("fri", "tues", "thurs"), in any case, with or without a
 * trailing period. The one definition every reader of typed text uses
 * (FallbackParser, RepeatParser). Where a short form can be an ordinary word
 * ("Sat down", "Sun Valley") is for each reader to weigh, by isShort().
 */
final class WeekdayName
{
    /** A weekday's name, no capturing group; match with the /i flag. */
    public const PATTERN = '(?:sun(?:day)?|mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?)\.?';

    private const FULL = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** 0 for Sunday to 6 for Saturday (PHP's date('w')), or null for no weekday's name. */
    public static function number(string $name): ?int
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if (preg_match('/^' . self::PATTERN . '$/', $name) !== 1) {
            return null;
        }
        return ['su' => 0, 'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6][substr($name, 0, 2)];
    }

    /** Whether a weekday's name is a short form ("Sat", "Tues."), which may be an ordinary word. */
    public static function isShort(string $name): bool
    {
        return !in_array(strtolower(rtrim(trim($name), '.')), self::FULL, true);
    }
}
