<?php

declare(strict_types=1);

namespace BetterCal\Support;

/** Questions on a terminal, for bin/install.php and bin/update.php. */
final class Cli
{
    public static function say(string $text = ''): void
    {
        fwrite(STDOUT, $text . "\n");
    }

    public static function warn(string $text): void
    {
        fwrite(STDERR, $text . "\n");
    }

    public static function interactive(): bool
    {
        return stream_isatty(STDIN);
    }

    /** A line of input, or $default when the answer is empty. */
    public static function ask(string $prompt, string $default = ''): string
    {
        fwrite(STDOUT, $prompt . ($default !== '' ? " [$default]" : '') . ': ');
        $line = fgets(STDIN);
        $answer = $line === false ? '' : trim($line);
        return $answer === '' ? $default : $answer;
    }

    /** A line typed without echo (a password). Falls back to visible input where stty is unavailable, and says so. */
    public static function askHidden(string $prompt): string
    {
        fwrite(STDOUT, $prompt . ': ');
        $stty = function_exists('shell_exec') ? trim((string) @shell_exec('stty -g 2>/dev/null')) : '';
        if ($stty !== '') {
            @shell_exec('stty -echo 2>/dev/null');
        } else {
            fwrite(STDOUT, '(this terminal will show what you type) ');
        }
        $line = fgets(STDIN);
        if ($stty !== '') {
            @shell_exec('stty ' . escapeshellarg($stty) . ' 2>/dev/null');
            fwrite(STDOUT, "\n");
        }
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    public static function confirm(string $prompt, bool $default = false): bool
    {
        $answer = strtolower(self::ask($prompt . ($default ? ' (Y/n)' : ' (y/N)')));
        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }
}
