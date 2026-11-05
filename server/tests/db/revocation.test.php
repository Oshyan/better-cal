<?php

declare(strict_types=1);

// Every write made under a request rechecks the request's credential at the
// write boundary (Undo::write): a session or API key revoked after the
// request signed in and before its write commits nothing, for the routes
// added since 1.0 that carried no credential of their own (a calendar's
// place in the list, filing a calendar in a folder and taking it out, an
// event's looked-up place). With the credential still valid, each goes
// through.

use BetterCal\Domain\RequestAuthority;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
    $two = (int) $d->calendars->create($u, ['name' => 'Sample two'])['id'];
    $folder = (int) $d->folders->create($u, ['name' => 'Sample folder'])['id'];
    $d->folders->place($u, $folder, $home, 0);
    $event = (int) $d->events->create($u, eventAt($home, 'Sample supper', '2026-12-16') + ['location' => 'Sample Hall'])['eventId'];
    $state = static fn(): array => [
        $db->all('SELECT id, position FROM calendars WHERE user_id = ? ORDER BY id', [$u]),
        $db->all('SELECT calendar_id, folder_id, position FROM calendar_folders WHERE folder_id = ? ORDER BY calendar_id', [$folder]),
        $db->one('SELECT location_lat, location_lng, location_source FROM events WHERE id = ?', [$event]),
        (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]),
    ];
    $routes = [
        'POST /calendars/:id/position' => static fn() => $d->calendars->placeInList($u, $two, 0),
        'POST /folders/:id/calendars/:calendarId' => static fn() => $d->folders->place($u, $folder, $two, 0),
        'DELETE /folders/:id/calendars/:calendarId' => static fn() => $d->folders->unfile($u, $folder, $home),
        'POST /events/:id/place' => static fn() => $d->events->placeFromLookup($u, $event, 'Sample Hall', 45.5, -122.6, 'photon'),
    ];
    foreach ($routes as $route => $call) {
        foreach (requestCredentials($db, $u) as $label => [$authority, $revoke, $code]) {
            $revoke();
            $before = $state();
            checkEq("revocation ($label): $route revoked after signing in, before its write, is refused", $code,
                refusal(fn() => RequestAuthority::during($authority, $call)));
            checkEq("revocation ($label): $route commits nothing", $before, $state());
        }
    }
    foreach ($routes as $route => $call) {
        [$authority] = requestCredentials($db, $u)['API key'];
        $before = $state();
        checkEq("revocation: $route with the credential still valid goes through", null, refusal(fn() => RequestAuthority::during($authority, $call)));
        check("revocation: $route changes what it says", $state() !== $before);
    }
};
