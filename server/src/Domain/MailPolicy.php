<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/** Owner preferences, not sender authentication. One list for Review and settings. */
final class MailPolicy
{
    public const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'yahoo.com', 'ymail.com', 'rocketmail.com',
        'aol.com', 'proton.me', 'protonmail.com', 'pm.me', 'fastmail.com', 'hey.com',
        'mail.com', 'gmx.com', 'gmx.net', 'zoho.com', 'yandex.com', 'yandex.ru',
    ];

    public static function address(string $value): ?string
    {
        $value = strtolower(trim($value));
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /** Shared domains and their subdomains cannot acquire broad approval. */
    public static function domainAllowed(string $domain): bool
    {
        foreach (self::SHARED_DOMAINS as $shared) {
            if ($domain === $shared || str_ends_with($domain, '.' . $shared)) return false;
        }
        // Regional addresses of the most common shared mailbox brands.
        return preg_match('/(?:^|\.)(?:yahoo|hotmail|outlook|live|gmx)\.[a-z.]+$/', $domain) !== 1;
    }

    public static function domainFor(string $address): ?string
    {
        $address = self::address($address);
        if ($address === null) return null;
        $domain = substr($address, strrpos($address, '@') + 1);
        return self::domainAllowed($domain) ? $domain : null;
    }

    /** Canonical entries: an exact address, a domain, or *.example.com for its subdomains. */
    public static function rules(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw HttpError::badRequest('mailApprovedSenders must be a list of addresses or domains');
        }
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw HttpError::badRequest('Each approved sender must be text');
            $value = strtolower(trim($value));
            $problem = self::ruleProblem($value);
            if ($problem === 'shared') {
                throw HttpError::badRequest('Shared mail providers can only be approved as exact email addresses.');
            }
            if ($problem !== null) throw HttpError::badRequest('Invalid approved sender: ' . $value);
            $out[$value] = true;
        }
        return array_keys($out);
    }

    /** Why a canonical (lowercased, trimmed) entry can't be a rule: 'invalid', 'shared', or null when it can. */
    private static function ruleProblem(string $value): ?string
    {
        if (self::address($value) !== null) return null;
        $domain = str_starts_with($value, '*.') ? substr($value, 2) : $value;
        if (strlen($domain) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain) !== 1) {
            return 'invalid';
        }
        return self::domainAllowed($domain) ? null : 'shared';
    }

    public const LIST_TEXT_MAX = 200_000;
    public const LIST_LINES_MAX = 5_000;

    /**
     * The approved senders typed or pasted as text, one per line, for the
     * bulk editor: what can be approved, in order with duplicates merged, and
     * each line that can't, with the reason, so one bad line never sinks the
     * rest. A pasted CSV works too (the first column is used), as do
     * "Name <address>" and "@example.com". Blank lines and # comments are
     * skipped. Nothing is saved here.
     *
     * @return array{rules: list<string>, invalid: list<array{line: string, reason: string}>}
     */
    public static function parseList(string $text): array
    {
        if (strlen($text) > self::LIST_TEXT_MAX) {
            throw HttpError::badRequest('That list is too long to read at once.');
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (count($lines) > self::LIST_LINES_MAX) {
            throw HttpError::badRequest('That list has more than ' . number_format(self::LIST_LINES_MAX) . ' lines.');
        }
        $rules = [];
        $invalid = [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $entry = $line;
            if (preg_match('/<([^<>]+)>/', $entry, $m) === 1) {
                $entry = $m[1]; // "Example Bookings <bookings@example.com>"
            } else {
                $entry = preg_split('/[,;\t]/', $entry)[0]; // a CSV row: its first column
            }
            $entry = strtolower(trim($entry, " \t\"'"));
            if (str_starts_with($entry, 'mailto:')) $entry = substr($entry, 7);
            if (str_starts_with($entry, '@')) $entry = substr($entry, 1);
            $problem = $entry === '' ? 'invalid' : self::ruleProblem($entry);
            if ($problem === null) {
                $rules[$entry] = true;
                continue;
            }
            $invalid[] = [
                'line' => mb_substr($line, 0, 200),
                'reason' => $problem === 'shared'
                    ? 'a shared mail provider, which can only be approved as an exact address'
                    : 'not an address or a domain',
            ];
        }
        return ['rules' => array_keys($rules), 'invalid' => $invalid];
    }

    public static function addsAutomatically(array $settings, string $from): bool
    {
        $mode = $settings['mailEventMode'] ?? 'approved';
        if ($mode === 'any') return true;
        if ($mode !== 'approved' || ($address = self::address($from)) === null) return false;
        $domain = substr($address, strrpos($address, '@') + 1);
        foreach ($settings['mailApprovedSenders'] ?? [] as $rule) {
            if ($rule === $address) return true;
            if (!self::domainAllowed($domain)) continue;
            if ($rule === $domain) return true;
            if (is_string($rule) && str_starts_with($rule, '*.') && self::domainAllowed(substr($rule, 2))
                && str_ends_with($domain, substr($rule, 1)) && $domain !== substr($rule, 2)) return true;
        }
        return false;
    }

    /** Caller holds the account lock; approval and adding the chosen item commit together. */
    public static function approve(Db $db, int $userId, string $from, string $scope): void
    {
        $rule = match ($scope) {
            'address' => self::address($from),
            'domain' => self::domainFor($from),
            default => null,
        };
        if ($rule === null) throw HttpError::badRequest('This sender cannot be approved with that scope.');
        $raw = $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $settings = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (($settings['mailEventMode'] ?? 'approved') !== 'approved') {
            throw HttpError::conflict('mail_policy_changed', 'Your email setting changed. Reload Review before approving this sender.');
        }
        $settings['mailApprovedSenders'] = self::rules([...($settings['mailApprovedSenders'] ?? []), $rule]);
        $db->update('users', ['settings_json' => json_encode($settings)], 'id = ?', [$userId]);
    }
}
