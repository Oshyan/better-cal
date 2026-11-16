<?php

declare(strict_types=1);

// Which events a filter's action takes: its action (hide, dim, highlight)
// and its side (appliesTo: the events that match, or the ones that do not),
// for every kind, decided by Filters::takesAction. Each of the six for a
// keyword, a regex and an AI prompt filter, with an event that matches, one
// that does not, and for a prompt one not judged yet (which takes no action
// on either side), in the calendar window and in search. Then the fill of
// migration 080 (a prompt filter made before it takes the unmatched, a
// keyword one the matched), the defaults by kind, and patching the side.
// Prompt verdicts are written here as the worker writes them; no model is
// called. Invented events only.

use BetterCal\Domain\Filters;
use BetterCal\Domain\Labels;
use BetterCal\Domain\Search;
use BetterCal\Domain\Settings;
use BetterCal\Http\Controllers\SearchController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

return static function (Db $db): void {
    $kinds = [
        'keyword' => ['pattern' => 'trail', 'fields' => ['title']],
        'regex' => ['pattern' => 'tra[i]l\b', 'fields' => ['title']],
        'prompt' => ['prompt' => 'outdoor things'],
    ];
    $mark = static fn(?array $o): string => $o === null ? 'hidden' : (!empty($o['dimmed']) ? 'dimmed' : (!empty($o['highlighted']) ? 'highlighted' : 'plain'));
    foreach ($kinds as $type => $config) {
        foreach (['hide', 'dim', 'highlight'] as $action) {
            foreach (['match', 'nonmatch'] as $side) {
                scenario("filter sides: $type, $action events that " . ($side === 'match' ? 'match' : "don't match"), function () use ($db, $type, $config, $action, $side, $mark): void {
                    $u = freshUser($db);
                    $d = domains($db);
                    $home = (int) Settings::defaultCalendarId($db, $u);
                    $matches = (int) $d->events->create($u, eventAt($home, 'Sample trail walk', '2027-04-06'))['eventId'];
                    $misses = (int) $d->events->create($u, eventAt($home, 'Sample tax webinar', '2027-04-07'))['eventId'];
                    $unknown = (int) $d->events->create($u, eventAt($home, 'Sample walk and talk', '2027-04-08'))['eventId'];
                    $filters = new Filters($db, $d->undo, new JobQueue($db));
                    $made = $filters->create($u, ['scope' => 'global', 'type' => $type, 'action' => $action, 'appliesTo' => $side,
                        'config' => $config + ($action === 'highlight' ? ['color' => '#4f9d69'] : [])]);
                    checkEq("$type $action $side: saved as asked", $side, $made['appliesTo']);
                    if ($type === 'prompt') {
                        $db->insert('filter_evals', ['filter_id' => (int) $made['id'], 'event_id' => $matches, 'verdict' => 'pass', 'score' => 0.9]);
                        $db->insert('filter_evals', ['filter_id' => (int) $made['id'], 'event_id' => $misses, 'verdict' => 'fail', 'score' => 0.1]);
                    }
                    // A keyword or regex filter knows about every event: the third simply does not match.
                    $expect = [
                        $matches => $side === 'match',
                        $misses => $side === 'nonmatch',
                        $unknown => $type === 'prompt' ? false : $side === 'nonmatch',
                    ];
                    $done = ['hide' => 'hidden', 'dim' => 'dimmed', 'highlight' => 'highlighted'][$action];
                    $names = [$matches => 'an event that matches', $misses => 'one that does not', $unknown => $type === 'prompt' ? 'one not judged yet' : 'another that does not'];

                    $win = [];
                    foreach ($d->events->window($u, new \DateTimeImmutable('2027-04-01T00:00:00Z'), new \DateTimeImmutable('2027-05-01T00:00:00Z'), null, null, false) as $o) {
                        $win[(int) $o['eventId']] = $o;
                    }
                    $route = new SearchController(new Search($db, new Labels($db)), $d->events, $filters, new Labels($db));
                    $req = new Request('GET', '/search', ['q' => 'Sample', 'when' => 'all']);
                    $req->user = ['id' => $u];
                    $found = [];
                    foreach (json_decode($route->search($req)->body, true)['results'] as $o) {
                        $found[(int) $o['eventId']] = $o;
                    }
                    foreach ($expect as $id => $taken) {
                        checkEq("$type $action $side: the window, {$names[$id]}", $taken ? $done : 'plain', $mark($win[$id] ?? null));
                        checkEq("$type $action $side: search, {$names[$id]}", $taken ? $done : 'plain', $mark($found[$id] ?? null));
                        if ($taken && $action === 'highlight') {
                            checkEq("$type highlight $side: in the filter's colour", '#4f9d69', $win[$id]['highlightColor'] ?? null);
                        }
                        if ($taken && $action !== 'hide') {
                            $reason = $win[$id]['filterReason'] ?? [];
                            checkEq("$type $action $side: the reason names the filter and its side",
                                [$type, $type === 'prompt' ? $side : ($side === 'match' ? 'title' : 'nonmatch')],
                                [$reason['type'] ?? null, $type === 'prompt' ? ($reason['appliesTo'] ?? null) : ($reason['field'] ?? $reason['appliesTo'] ?? null)]);
                        }
                    }
                });
            }
        }
    }

    scenario('filter sides: defaults by kind, patching the side, refusing another value', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $filters = new Filters($db, $d->undo, new JobQueue($db));
        $prompt = $filters->create($u, ['scope' => 'global', 'type' => 'prompt', 'action' => 'dim', 'config' => ['prompt' => 'outdoor things']]);
        $keyword = $filters->create($u, ['scope' => 'global', 'type' => 'keyword', 'action' => 'dim', 'config' => ['pattern' => 'webinar']]);
        $regex = $filters->create($u, ['scope' => 'global', 'type' => 'regex', 'action' => 'dim', 'config' => ['pattern' => 'web(inar|cast)']]);
        checkEq('filter sides: a new AI prompt filter takes the events judged not to fit', 'nonmatch', $prompt['appliesTo']);
        checkEq('filter sides: a new keyword filter takes the events that match', 'match', $keyword['appliesTo']);
        checkEq('filter sides: a new regex filter takes the events that match', 'match', $regex['appliesTo']);
        checkEq('filter sides: patched to the other side', 'nonmatch', $filters->patch($u, (int) $keyword['id'], ['appliesTo' => 'nonmatch'])['appliesTo']);
        checkEq('filter sides: a patch of the action keeps the side', 'nonmatch', $filters->patch($u, (int) $keyword['id'], ['action' => 'highlight'])['appliesTo']);
        checkEq('filter sides: a patch of the type keeps the side', 'nonmatch', $filters->patch($u, (int) $keyword['id'], ['type' => 'regex', 'config' => ['pattern' => 'webinar']])['appliesTo']);
        checkEq('filter sides: listed with it', 'nonmatch', array_column($filters->listAll($u), 'appliesTo', 'id')[(int) $keyword['id']]);
        checkEq('filter sides: another value is refused on create', 'invalid_request', refusal(fn() => $filters->create($u, ['scope' => 'global', 'type' => 'keyword', 'action' => 'dim', 'appliesTo' => 'both', 'config' => ['pattern' => 'x']])));
        checkEq('filter sides: and on a patch', 'invalid_request', refusal(fn() => $filters->patch($u, (int) $prompt['id'], ['appliesTo' => null])));
        checkEq('filter sides: a refused patch changes nothing', 'nonmatch', $filters->get($u, (int) $prompt['id'])['applies_to']);
    });

    scenario('filter sides: migration 080 fills the side from each filter\'s kind', function () use ($db): void {
        $u = freshUser($db);
        // As before 1.5.8: no side stored. Every prompt filter took the
        // events judged not to fit, every keyword and regex filter the ones
        // that match; filled so, an upgrade changes no filter's effect.
        $db->run('ALTER TABLE `filters` DROP COLUMN `applies_to`');
        $made = [];
        foreach ([['prompt', 'dim', '{"prompt":"outdoor things"}'], ['prompt', 'hide', '{"prompt":"family days"}'], ['keyword', 'hide', '{"pattern":"webinar","fields":["title"]}'], ['regex', 'highlight', '{"pattern":"tra[i]l","fields":["title"]}']] as [$type, $action, $config]) {
            $made[$type . ' ' . $action] = (int) $db->insert('filters', ['user_id' => $u, 'scope' => 'global', 'scope_id' => null, 'type' => $type, 'config_json' => $config, 'action' => $action, 'enabled' => 1]);
        }
        $said = (require dirname(__DIR__, 2) . '/migrations/080_filters_applies_to.php')($db);
        check('filter sides migration: says what it filled', str_contains($said, 'added filters.applies_to'), $said);
        $sides = [];
        foreach ($made as $name => $id) {
            $sides[$name] = (string) $db->scalar('SELECT applies_to FROM filters WHERE id = ?', [$id]);
        }
        checkEq('filter sides migration: prompt filters take the unmatched, keyword and regex the matched',
            ['prompt dim' => 'nonmatch', 'prompt hide' => 'nonmatch', 'keyword hide' => 'match', 'regex highlight' => 'match'], $sides);
        checkEq('filter sides migration: run again, it changes nothing', 'filters.applies_to already present', (require dirname(__DIR__, 2) . '/migrations/080_filters_applies_to.php')($db));
    });
};
