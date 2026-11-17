<?php

declare(strict_types=1);

// Which API key made a change: every journal entry recorded under a request
// signed in with a key keeps the key's id and its name as it was (Undo::
// record, the one function that writes entries), and Activity serves them
// with the entry; after the key is revoked the entry still names it, marked
// revoked. A browser session's entries and the server's own carry no key.
// Invented data.

use BetterCal\Domain\Activity;
use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\ApiTokens;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    scenario('activity key: a change made with a key names it, and still does after the key is revoked', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $tokens = new ApiTokens($db);
        $made = $tokens->create($u, 'Sample agent');
        $tokenId = (int) ($made['id'] ?? $db->scalar('SELECT MAX(id) FROM api_tokens WHERE user_id = ?', [$u]));
        $other = (int) ($tokens->create($u, 'Sample phone')['id'] ?? $db->scalar('SELECT MAX(id) FROM api_tokens WHERE user_id = ?', [$u]));
        $session = 'sample-session-' . bin2hex(random_bytes(8));
        $db->run('INSERT INTO sessions (token_hash, user_id, csrf, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $session), $u, str_repeat('c', 32), '2099-01-01 00:00:00']);

        $byKey = RequestAuthority::during(RequestAuthority::credential($u, $tokenId, null),
            fn() => ActivityContext::with('api', fn() => (int) $d->events->create($u, eventAt($home, 'Team sync', '2026-12-09'))['eventId']));
        $keyEntry = lastEntry($db, $u);
        RequestAuthority::during(RequestAuthority::credential($u, $other, null),
            fn() => ActivityContext::with('caldav', fn() => $d->events->patch($u, $byKey, ['title' => 'Team sync, moved'])));
        $phoneEntry = lastEntry($db, $u);
        RequestAuthority::during(RequestAuthority::credential($u, null, $session), fn() => $d->events->patch($u, $byKey, ['title' => 'Team sync, again']));
        $sessionEntry = lastEntry($db, $u);
        $d->events->patch($u, $byKey, ['title' => 'Team sync, by the server']);
        $serverEntry = lastEntry($db, $u);

        $served = static function () use ($db, $u): array {
            $out = [];
            foreach ((new Activity($db))->list($u, ['limit' => 100])['entries'] as $e) {
                $out[$e['id']] = $e['key'] ?? 'none';
            }
            return $out;
        };
        $now = $served();
        checkEq('activity key: an API change names its key', ['id' => $tokenId, 'name' => 'Sample agent', 'revoked' => false], $now[$keyEntry] ?? 'missing');
        checkEq('activity key: a CalDAV change names its key', ['id' => $other, 'name' => 'Sample phone', 'revoked' => false], $now[$phoneEntry] ?? 'missing');
        checkEq('activity key: a browser session\'s change has none', 'none', $now[$sessionEntry] ?? 'missing');
        checkEq('activity key: the server\'s own change has none', 'none', $now[$serverEntry] ?? 'missing');
        checkEq('activity key: stored on the entry', [(string) $tokenId, 'Sample agent'],
            array_values(array_map('strval', $db->one('SELECT token_id, token_name FROM mutations WHERE id = ?', [$keyEntry]))));

        $tokens->revoke($u, $tokenId);
        $after = $served();
        checkEq('activity key: after the key is revoked the entry still names it, as revoked', ['id' => $tokenId, 'name' => 'Sample agent', 'revoked' => true], $after[$keyEntry] ?? 'missing');
        checkEq('activity key: another key is not marked revoked', false, $after[$phoneEntry]['revoked'] ?? null);
        // Undo of the revoked key's change is still the person's to make, from a session.
        $undone = RequestAuthority::during(RequestAuthority::credential($u, null, $session), fn() => (new BetterCal\Domain\Undo($db))->undoById($u, $keyEntry, true));
        check('activity key: an entry whose key was revoked can still be undone from a session', is_array($undone));
    });
};
