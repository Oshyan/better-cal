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

    /**
     * Wildcard bases a rule may not use: *.co.uk would approve every sender
     * under a public suffix, as would *.com. The list is short on purpose,
     * the familiar two-part suffixes rather than the whole public suffix
     * list; a wildcard needs a domain of its own under it.
     */
    public const PUBLIC_SUFFIXES = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'nhs.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au', 'asn.au',
        'co.nz', 'net.nz', 'org.nz', 'ac.nz', 'govt.nz', 'school.nz',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp', 'gr.jp', 'ad.jp', 'ed.jp', 'lg.jp',
        'co.za', 'org.za', 'net.za', 'ac.za', 'gov.za', 'web.za',
        'com.br', 'net.br', 'org.br', 'gov.br', 'edu.br',
        'com.mx', 'org.mx', 'net.mx', 'gob.mx', 'edu.mx',
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'ac.in', 'edu.in', 'gov.in', 'res.in',
        'com.sg', 'net.sg', 'org.sg', 'edu.sg', 'gov.sg',
        'com.hk', 'net.hk', 'org.hk', 'edu.hk', 'gov.hk', 'idv.hk',
        'com.tw', 'net.tw', 'org.tw', 'edu.tw', 'gov.tw', 'idv.tw',
        'co.kr', 'ne.kr', 'or.kr', 'ac.kr', 'go.kr', 're.kr', 'pe.kr',
        'com.ar', 'net.ar', 'org.ar', 'edu.ar', 'gob.ar',
        'com.tr', 'net.tr', 'org.tr', 'edu.tr', 'gov.tr', 'gen.tr', 'web.tr',
        'com.cn', 'net.cn', 'org.cn', 'edu.cn', 'gov.cn', 'ac.cn',
        'co.il', 'org.il', 'net.il', 'ac.il', 'gov.il', 'muni.il',
        'com.my', 'net.my', 'org.my', 'edu.my', 'gov.my',
        'co.id', 'or.id', 'ac.id', 'go.id', 'web.id', 'my.id',
        'com.ph', 'net.ph', 'org.ph', 'edu.ph', 'gov.ph',
        'co.th', 'or.th', 'ac.th', 'go.th', 'in.th',
        'com.vn', 'net.vn', 'org.vn', 'edu.vn', 'gov.vn',
        'com.pk', 'net.pk', 'org.pk', 'edu.pk', 'gov.pk',
        'com.eg', 'com.sa', 'com.ua', 'com.pl', 'com.pe', 'com.co', 'com.ve', 'com.uy', 'com.ec', 'com.py', 'com.bo',
        'com.ng', 'co.ke', 'com.gh', 'co.ug', 'co.tz',
        'co.at', 'or.at', 'ac.at', 'gv.at', 'co.hu', 'com.pt', 'com.gr', 'com.ro', 'com.ru', 'org.ru', 'net.ru',
    ];

    /**
     * An address as rules store and match it: the domain in the form
     * domain() gives, the local part lowercased (every letter alike, so an
     * SMTPUTF8 local part works), or null when it isn't an address.
     */
    public static function address(string $value): ?string
    {
        $value = trim($value);
        $at = strrpos($value, '@');
        if ($at === false || !mb_check_encoding($value, 'UTF-8')) return null;
        $domain = self::domain(substr($value, $at + 1));
        if ($domain === null) return null;
        $value = mb_strtolower(substr($value, 0, $at)) . '@' . $domain;
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false ? $value : null;
    }

    /**
     * A domain as rules store and match it: lowercase ASCII, with any
     * non-ASCII label punycoded (IDNA 2008), or null when it isn't a
     * hostname. Without PHP's intl extension a non-ASCII name can't be
     * converted, so it is refused rather than stored in a form that would
     * never match a sender.
     */
    public static function domain(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/[\x80-\xff]/', $value) === 1) {
            if (!function_exists('idn_to_ascii') || !mb_check_encoding($value, 'UTF-8')) return null;
            $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) return null;
            $value = $ascii;
        }
        $ok = strlen($value) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9]{1,59})$/D', $value) === 1;
        return $ok ? $value : null;
    }

    /** A wildcard over this base would cover every domain under a public suffix. */
    private static function tooBroad(string $base): bool
    {
        return !str_contains($base, '.') || in_array($base, self::PUBLIC_SUFFIXES, true);
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

    /**
     * Canonical entries for a list being saved: an exact address, a domain,
     * or *.example.com for the domains one label under it. A line that
     * can't be a rule refuses the whole save and is named, so the owner
     * knows which one to fix. The stored list is never re-read through
     * this: a line an older version accepted and this one refuses (a
     * wildcard over a public suffix, an address with an IP in it) would
     * otherwise break every save and every "Always add from" until it was
     * found and removed by hand (review of #196). Such a line stays in the
     * list, matches nothing (addsAutomatically), and is named here only
     * when the list is saved again.
     */
    public static function rules(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw HttpError::badRequest('mailApprovedSenders must be a list of addresses or domains');
        }
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw HttpError::badRequest('Each approved sender must be text');
            $value = strtolower(trim($value));
            [$rule, $problem] = self::rule($value);
            if ($problem === 'shared') {
                throw HttpError::badRequest($value . ' is a shared mail provider, which can only be approved as an exact email address.');
            }
            if ($problem === 'public') {
                throw HttpError::badRequest($value . ' would cover every domain under a public suffix; approve the domains you mean instead.');
            }
            if ($problem === 'intl') {
                throw HttpError::badRequest($value . ' has a non-ASCII domain name, which this server cannot convert: PHP\'s intl extension is missing.');
            }
            if ($problem !== null) throw HttpError::badRequest('Invalid approved sender: ' . $value);
            $out[$rule] = true;
        }
        return array_keys($out);
    }

    /**
     * The stored form of a trimmed entry and null, or null and why it can't
     * be a rule: 'invalid', 'shared', 'public' (a wildcard over a public
     * suffix) or 'intl' (a non-ASCII name this PHP can't convert).
     *
     * @return array{?string, ?string}
     */
    private static function rule(string $value): array
    {
        if (($address = self::address($value)) !== null) return [$address, null];
        $wild = str_starts_with($value, '*.');
        $base = $wild ? substr($value, 2) : $value;
        if ($wild && !str_contains($base, '.')) return [null, 'public'];
        $domain = self::domain($base);
        if ($domain === null) {
            $nonAscii = preg_match('/[\x80-\xff]/', $value) === 1;
            return [null, $nonAscii && !function_exists('idn_to_ascii') ? 'intl' : 'invalid'];
        }
        if ($wild && self::tooBroad($domain)) return [null, 'public'];
        if (!self::domainAllowed($domain)) return [null, 'shared'];
        return [$wild ? '*.' . $domain : $domain, null];
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
            [$rule, $problem] = $entry === '' ? [null, 'invalid'] : self::rule($entry);
            if ($rule !== null) {
                $rules[$rule] = true;
                continue;
            }
            $invalid[] = [
                'line' => mb_substr($line, 0, 200),
                'reason' => match ($problem) {
                    'shared' => 'a shared mail provider, which can only be approved as an exact address',
                    'public' => 'a wildcard over a public suffix, which would cover every domain under it',
                    'intl' => 'a non-ASCII domain name, which this server cannot convert without PHP\'s intl extension',
                    default => 'not an address or a domain',
                },
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
        // *.example.test covers one label below it (mail.example.test), so a
        // wildcard matches when its base is the sender's domain minus its
        // first label. A base that is a public suffix never matches, even
        // one saved before such wildcards were refused.
        $parent = substr($domain, strpos($domain, '.') + 1);
        foreach ($settings['mailApprovedSenders'] ?? [] as $rule) {
            if ($rule === $address) return true;
            if (!self::domainAllowed($domain)) continue;
            if ($rule === $domain) return true;
            if ($rule === '*.' . $parent && !self::tooBroad($parent)) return true;
        }
        return false;
    }

    /**
     * A rule as the owner should read it. A non-ASCII domain is stored as
     * punycode (domain()); shown only in Unicode it could pass for a
     * well-known name, and "Always add from" puts sender-chosen domains in
     * the list, so the Unicode form is shown with the stored one beside it:
     * "bücher.example (xn--bcher-kva.example)". An ASCII rule is shown as
     * it is, and so is one this PHP can't convert.
     */
    public static function display(string $rule): string
    {
        if (!str_contains($rule, 'xn--') || !function_exists('idn_to_utf8')) return $rule;
        $at = strrpos($rule, '@');
        $domain = $at === false ? $rule : substr($rule, $at + 1);
        $wild = str_starts_with($domain, '*.');
        $unicode = idn_to_utf8($wild ? substr($domain, 2) : $domain, IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($unicode) || $unicode === '' || ($wild ? '*.' . $unicode : $unicode) === $domain) return $rule;
        $shown = ($at === false ? '' : substr($rule, 0, $at + 1)) . ($wild ? '*.' : '') . $unicode;
        return $shown . ' (' . $rule . ')';
    }

    /**
     * Caller holds the account lock; approval and adding the chosen item
     * commit together. Only the rule being added is checked: the stored
     * list's other lines stay as they are, whatever this version makes of
     * them (rules()).
     */
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
        $stored = is_array($settings['mailApprovedSenders'] ?? null) ? array_values(array_filter($settings['mailApprovedSenders'], 'is_string')) : [];
        if (!in_array($rule, $stored, true)) $stored[] = $rule;
        $settings['mailApprovedSenders'] = $stored;
        $db->update('users', ['settings_json' => json_encode($settings)], 'id = ?', [$userId]);
    }
}
