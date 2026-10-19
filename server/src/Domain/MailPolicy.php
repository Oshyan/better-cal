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

    /** Canonical entries also support the later bulk editor's *.example.com syntax. */
    public static function rules(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw HttpError::badRequest('mailApprovedSenders must be a list of addresses or domains');
        }
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw HttpError::badRequest('Each approved sender must be text');
            $value = strtolower(trim($value));
            if (self::address($value) === null) {
                $domain = str_starts_with($value, '*.') ? substr($value, 2) : $value;
                if (strlen($domain) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain) !== 1) {
                    throw HttpError::badRequest('Invalid approved sender: ' . $value);
                }
                if (!self::domainAllowed($domain)) {
                    throw HttpError::badRequest('Shared mail providers can only be approved as exact email addresses.');
                }
            }
            $out[$value] = true;
        }
        return array_keys($out);
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
