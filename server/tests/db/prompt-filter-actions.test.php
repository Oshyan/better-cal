<?php

declare(strict_types=1);

// Which events an AI prompt filter's action takes, by the model's stored
// verdict (Filters::promptActionApplies): dim and highlight take the events
// judged to fit the description, hide the ones judged not to (it keeps what
// fits), and an event not judged yet takes none. The calendar window and
// search read the verdicts through the same set. Verdicts are written here
// as the worker writes them; no model is called. Invented events only.

use BetterCal\Domain\Filters;
use BetterCal\Domain\Labels;
use BetterCal\Domain\Search;
use BetterCal\Domain\Settings;
use BetterCal\Http\Controllers\SearchController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

return static function (Db $db): void {
    foreach (['hide', 'dim', 'highlight'] as $action) {
        scenario("prompt filter $action: which events it takes", function () use ($db, $action): void {
            $u = freshUser($db);
            $d = domains($db);
            $home = (int) Settings::defaultCalendarId($db, $u);
            $fits = (int) $d->events->create($u, eventAt($home, 'Sample trail walk', '2027-04-06'))['eventId'];
            $misses = (int) $d->events->create($u, eventAt($home, 'Sample tax webinar', '2027-04-07'))['eventId'];
            $unjudged = (int) $d->events->create($u, eventAt($home, 'Sample walk and talk', '2027-04-08'))['eventId'];
            $filters = new Filters($db, $d->undo, new JobQueue($db));
            $config = ['prompt' => 'outdoor things'] + ($action === 'highlight' ? ['color' => '#4f9d69'] : []);
            $filterId = (int) $filters->create($u, ['scope' => 'global', 'type' => 'prompt', 'action' => $action, 'config' => $config])['id'];
            $db->insert('filter_evals', ['filter_id' => $filterId, 'event_id' => $fits, 'verdict' => 'pass', 'score' => 0.9]);
            $db->insert('filter_evals', ['filter_id' => $filterId, 'event_id' => $misses, 'verdict' => 'fail', 'score' => 0.1]);

            $takesFitting = $action !== 'hide';
            $taken = $takesFitting ? $fits : $misses;
            $left = $takesFitting ? $misses : $fits;

            $byId = [];
            foreach ($d->events->window($u, new \DateTimeImmutable('2027-04-01T00:00:00Z'), new \DateTimeImmutable('2027-05-01T00:00:00Z'), null, null, false) as $o) {
                $byId[(int) $o['eventId']] = $o;
            }
            $mark = static fn(?array $o): string => $o === null ? 'hidden' : (!empty($o['dimmed']) ? 'dimmed' : (!empty($o['highlighted']) ? 'highlighted' : 'plain'));
            $done = ['hide' => 'hidden', 'dim' => 'dimmed', 'highlight' => 'highlighted'][$action];
            checkEq("prompt $action: the window, the event it takes", $done, $mark($byId[$taken] ?? null));
            checkEq("prompt $action: the window, the event it leaves", 'plain', $mark($byId[$left] ?? null));
            checkEq("prompt $action: the window, an event not judged yet", 'plain', $mark($byId[$unjudged] ?? null));
            if ($action === 'highlight') {
                checkEq('prompt highlight: in the filter\'s colour', '#4f9d69', $byId[$fits]['highlightColor'] ?? null);
            }
            if ($action !== 'hide') {
                checkEq("prompt $action: the reason names the filter", ['type' => 'prompt', 'pattern' => 'outdoor things'], $byId[$taken]['filterReason'] ?? null);
            }

            // Search reads the same verdicts the same way.
            $route = new SearchController(new Search($db, new Labels($db)), $d->events, $filters, new Labels($db));
            $req = new Request('GET', '/search', ['q' => 'Sample', 'when' => 'all']);
            $req->user = ['id' => $u];
            $found = [];
            foreach (json_decode($route->search($req)->body, true)['results'] as $o) {
                $found[(int) $o['eventId']] = $o;
            }
            checkEq("prompt $action: search, the event it takes", $done, $mark($found[$taken] ?? null));
            checkEq("prompt $action: search, the event it leaves", 'plain', $mark($found[$left] ?? null));
            checkEq("prompt $action: search, an event not judged yet", 'plain', $mark($found[$unjudged] ?? null));
        });
    }
};
