<?php

declare(strict_types=1);

// Event search ranking (docs/design/search-ranking.md): what the database
// does with the boolean-mode queries Search builds. First the two InnoDB
// behaviours the plan stands on, pinned against the server; then the
// tiers, the one-field rule, the address left out, the LIKE fallback and
// the Upcoming and Past split, on invented events; then the time a search
// takes over 20,000 events. Each block is a scenario(): a throw fails that
// block, and the next still runs.

use BetterCal\Domain\Labels;
use BetterCal\Domain\OutFeeds;
use BetterCal\Domain\Search;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;

return static function (Db $db): void {
    $d = domains($db);
    $search = new Search($db, new Labels($db));
    $now = new DateTimeImmutable('2026-11-06 12:00:00', new DateTimeZone('UTC'));
    $titles = static fn(array $rows): array => array_column($rows, 'title');
    $make = static function (int $u, string $title, string $day, array $more = []) use ($d, $db): int {
        return (int) $d->events->create($u, eventAt((int) Settings::defaultCalendarId($db, $u), $title, $day, $more))['eventId'];
    };

    scenario('search: the InnoDB behaviours the plan stands on', function () use ($db, $make, $search, $now, $titles): void {
        $u = freshUser($db);
        $make($u, 'The Who Tribute Night', '2026-12-02');
        $make($u, "Dinner with Pat O'Brien", '2026-12-03');
        $hits = static fn(string $against): array => array_column($db->all(
            'SELECT title FROM events WHERE user_id = ? AND MATCH(title) AGAINST (? IN BOOLEAN MODE) ORDER BY title', [$u, $against]), 'title');

        $minToken = (int) $db->scalar('SELECT @@innodb_ft_min_token_size');
        check('search pin: the server\'s minimum token length is read, not assumed (' . $minToken . ')', $minToken >= 1);
        $serverStopwords = array_values(array_unique(array_column($db->all('SELECT value FROM information_schema.INNODB_FT_DEFAULT_STOPWORD'), 'value')));
        sort($serverStopwords);
        $ours = Search::STOPWORDS;
        sort($ours);
        checkEq('search pin: the built-in stopword list is the server\'s', $serverStopwords, $ours);

        // A required stopword is not dropped: it empties the result, so
        // Search never sends one to MATCH.
        checkEq('search pin: a required stopword empties the result', [], $hits('+the +tribute'));
        checkEq('search pin: without it the event is found', ['The Who Tribute Night'], $hits('+tribute'));
        checkEq('search: a query with a stopword finds the event, the stopword matched by LIKE', ['The Who Tribute Night'],
            $titles($search->search($u, 'the tribute', 50, true, ['now' => $now])));

        // An apostrophe splits a word in the index as in the query: O'Brien
        // is stored as "o" (under the minimum, dropped) and "brien".
        checkEq('search pin: an apostrophe splits the stored word, so "brien" finds O\'Brien', ["Dinner with Pat O'Brien"], $hits('+brien'));
        checkEq('search pin: and "o\'brien" does too', ["Dinner with Pat O'Brien"], $hits("+o'brien"));
        checkEq('search pin: the joined spelling does not', [], $hits('+obrien'));
        checkEq('search pin: a phrase keeping the apostrophe matches', ["Dinner with Pat O'Brien"], $hits("\"pat o'brien\""));
        checkEq('search: "o\'brien" finds the event', ["Dinner with Pat O'Brien"], $titles($search->search($u, "o'brien", 50, true, ['now' => $now])));
        checkEq('search: "pat o\'brien" finds it', ["Dinner with Pat O'Brien"], $titles($search->search($u, "pat o'brien", 50, true, ['now' => $now])));
        checkEq('search: "obrien" does not find it', [], $search->search($u, 'obrien', 50, true, ['now' => $now]));
    });

    scenario('search: tiers, one field, no address, the groups', function () use ($db, $make, $search, $now, $titles): void {
        $u = freshUser($db);
        // Dated so date order alone would differ from tier order.
        $make($u, 'Labor Day Picnic', '2027-03-10');                                          // title phrase
        $make($u, 'Day of Labor Rally', '2027-03-05');                                        // title, both words apart
        $make($u, 'Sample Gathering', '2027-03-01', ['tagNames' => ['Day Labor']]);           // tag, both words apart
        $make($u, 'Laboratory Daytrip', '2027-02-01');                                        // title, word starts
        $make($u, 'Sample Visit', '2027-02-05', ['personNames' => ['Daya Laborde']]);         // person, word starts
        $make($u, 'Neighborhood potluck', '2026-12-01', ['description' => 'Our labor day picnic, bring a dish.']); // description phrase
        $make($u, 'Labor meeting', '2026-12-02', ['description' => 'A long day ahead.']);     // words split across fields
        $make($u, 'Office hours', '2026-12-03', ['location' => '12 Labor Day Lane, Sampleton']); // address only
        $make($u, 'Labor Day BBQ', '2025-09-01');                                             // past, title phrase
        $make($u, 'Labor Day Fair', '2024-09-02');                                            // past, title phrase, older
        $make($u, 'Autumn notes', '2026-01-10', ['description' => 'Labor day recap.']);       // past, description phrase, newer

        $all = $search->search($u, 'labor day', 50, true, ['now' => $now]);
        checkEq('search: upcoming by tier then soonest, then past by tier then newest', [
            'Labor Day Picnic', 'Sample Gathering', 'Day of Labor Rally', 'Laboratory Daytrip', 'Sample Visit', 'Neighborhood potluck',
            'Labor Day BBQ', 'Labor Day Fair', 'Autumn notes',
        ], $titles($all));
        checkEq('search: the groups split as before', [true, true, true, true, true, true, false, false, false], array_column($all, '_upcoming'));
        check('search: no OR, words split across title and description is no match', !in_array('Labor meeting', $titles($all), true));
        check('search: the address is not searched', !in_array('Office hours', $titles($all), true));
        checkEq('search: an address word alone finds nothing', [], $search->search($u, 'sampleton', 50, true, ['now' => $now]));

        checkEq('search: Upcoming alone, the same order', array_slice($titles($all), 0, 6), $titles($search->search($u, 'labor day', 50, true, ['when' => 'upcoming', 'now' => $now])));
        checkEq('search: Past alone, the same order', array_slice($titles($all), 6), $titles($search->search($u, 'labor day', 50, true, ['when' => 'past', 'now' => $now])));
        checkEq('search: past matches counted under Upcoming', 3, $search->countPast($u, 'labor day', ['now' => $now]));
        checkEq('search: the limit keeps the best tiers', ['Labor Day Picnic', 'Labor Day BBQ', 'Labor Day Fair'], $titles($search->search($u, 'labor day', 3, true, ['now' => $now])));

        checkEq('search: one word, title before description', ['Labor Day Picnic', 'Neighborhood potluck'], $titles($search->search($u, 'picnic', 50, true, ['now' => $now])));
        checkEq('search: as you type, the last word a word start', ['Labor Day Picnic', 'Day of Labor Rally', 'Laboratory Daytrip'],
            array_slice($titles($search->search($u, 'labor da', 50, true, ['when' => 'upcoming', 'now' => $now])), 0, 3));
        checkEq('search: # searches tag names only, by the word rule', ['Sample Gathering'], $titles($search->search($u, '#day lab', 50, true, ['now' => $now])));
        checkEq('search: @ searches people only, by the word rule', ['Sample Visit'], $titles($search->search($u, '@daya', 50, true, ['now' => $now])));
        checkEq('search: a tag name inside a word is no match', [], $search->search($u, '#abor', 50, true, ['now' => $now]));
        checkEq('search: typed operators are only separators', $titles($all), $titles($search->search($u, '+labor -"day"*', 50, true, ['now' => $now])));
    });

    scenario('search: short words and stopwords fall to LIKE', function () use ($db, $make, $search, $now, $titles): void {
        $u = freshUser($db);
        $make($u, 'Sample 5K Fun Run', '2026-12-05');
        $make($u, 'The Who Tribute Night', '2026-12-06');
        $make($u, 'Sample swim', '2026-12-07', ['description' => 'A 5k swim, then the who quiz.']);
        checkEq('search: a short word alone is matched with LIKE, title first', ['Sample 5K Fun Run', 'Sample swim'], $titles($search->search($u, '5k', 50, true, ['now' => $now])));
        checkEq('search: only stopwords, every word in one field, title first', ['The Who Tribute Night', 'Sample swim'], $titles($search->search($u, 'the who', 50, true, ['now' => $now])));
        checkEq('search: a short word with an indexed one stays in the same field', ['Sample 5K Fun Run'], $titles($search->search($u, 'fun 5k', 50, true, ['now' => $now])));
    });

    scenario('search: a running series and a past event split as before', function () use ($db, $make, $search, $now): void {
        $u = freshUser($db);
        $make($u, 'Team standup', '2026-01-05', ['rrule' => 'FREQ=WEEKLY']);
        $make($u, 'Old standup notes', '2025-05-01');
        $all = $search->search($u, 'standup', 50, true, ['now' => $now]);
        checkEq('search: the series is upcoming, the old event past', [['Team standup', true], ['Old standup notes', false]],
            array_map(static fn(array $r): array => [$r['title'], $r['_upcoming']], $all));
        check('search: the series shows its next date', ($all[0]['end_utc'] ?? '') >= $now->format('Y-m-d H:i:s'));
        $past = $search->search($u, 'standup', 50, true, ['when' => 'past', 'now' => $now]);
        checkEq('search: under Past the series shows its latest ended date, newest first', ['Team standup', 'Old standup notes'], array_column($past, 'title'));
        check('search: and that date has ended', ($past[0]['end_utc'] ?? '9') <= $now->format('Y-m-d H:i:s'));
    });

    scenario('search: the time a search takes over 20,000 events', function () use ($db, $make, $search, $now): void {
        $u = freshUser($db);
        $first = $make($u, 'Sample template', '2026-01-01');
        $template = $db->one('SELECT * FROM events WHERE id = ?', [$first]);
        unset($template['id']);
        $cols = array_keys($template);
        $rowSql = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        // About 1,100 invented words; the query's words are rare, as in a
        // real calendar, except "sample", in every description, the worst
        // case: every row a match.
        $syllables = ['ba', 'ko', 'ri', 'ten', 'mal', 'sor', 'vin', 'del', 'pra', 'lum'];
        $pool = [];
        foreach ($syllables as $a) {
            foreach ($syllables as $b) {
                $pool[] = $a . $b;
                foreach ($syllables as $c) {
                    $pool[] = $a . $b . $c;
                }
            }
        }
        mt_srand(7);
        $pick = static fn(int $n): string => implode(' ', array_map(static fn() => $pool[mt_rand(0, count($pool) - 1)], range(1, $n)));
        $total = 20000;
        for ($n = 0; $n < $total; $n += 500) {
            $params = [];
            for ($i = $n; $i < $n + 500; $i++) {
                $day = (new DateTimeImmutable('2024-01-01 08:00:00'))->modify('+' . intdiv($i, 10) . ' days');
                $title = ucfirst($pick(3)) . ($i % 100 === 0 ? ' labor day' : ($i % 100 === 1 ? ' day of labor' : '')) . ' ' . $i;
                $description = 'Sample ' . $pick(25) . ($i % 50 === 2 ? ' labor day' : '') . '.';
                $row = ['uid' => 'search-sample-' . $i . '@example.test', 'title' => $title, 'description' => $description,
                    'location' => $i . ' Sample Street', 'start_utc' => $day->format('Y-m-d H:i:s'), 'end_utc' => $day->modify('+1 hour')->format('Y-m-d H:i:s')] + $template;
                foreach ($cols as $c) {
                    $params[] = $row[$c];
                }
            }
            $db->run('INSERT INTO events (`' . implode('`, `', $cols) . '`) VALUES ' . implode(', ', array_fill(0, 500, $rowSql)), $params);
        }
        $time = static function (callable $fn): float {
            $runs = [];
            for ($r = 0; $r < 5; $r++) {
                $t = hrtime(true);
                $fn();
                $runs[] = (hrtime(true) - $t) / 1e6;
            }
            sort($runs);
            return $runs[2];
        };
        $report = [];
        foreach (['labor day' => 'all', 'labor da' => 'upcoming', 'kotenri' => 'all', 'the who' => 'all', 'sample' => 'all'] as $q => $when) {
            $count = 0;
            $ms = $time(function () use ($search, $u, $q, $when, $now, &$count): void {
                $count = count($search->search($u, $q, 50, true, ['when' => $when, 'now' => $now]));
            });
            $report[] = sprintf('"%s" (%s, %d rows) %.1f ms', $q, $when, $count, $ms);
        }
        $countMs = $time(fn() => $search->countPast($u, 'labor day', ['now' => $now]));
        $report[] = sprintf('countPast "labor day" %.1f ms', $countMs);
        check('search: 20,000 events searched', (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ?', [$u]) === $total + 1);

        // The migration's cost at this size: the two indexes built on a copy.
        $db->run('CREATE TABLE search_copy LIKE events');
        $db->run('ALTER TABLE search_copy DROP INDEX ft_events_title, DROP INDEX ft_events_description');
        $db->run('INSERT INTO search_copy SELECT * FROM events WHERE user_id = ?', [$u]);
        $t = hrtime(true);
        $db->run('ALTER TABLE search_copy ADD FULLTEXT KEY ft_events_title (title)');
        $db->run('ALTER TABLE search_copy ADD FULLTEXT KEY ft_events_description (description)');
        $report[] = sprintf('both indexes built over %d rows in %.0f ms', $total + 1, (hrtime(true) - $t) / 1e6);
        $db->run('DROP TABLE search_copy');
        echo '    search timing (median of 5): ' . implode('; ', $report) . "\n";
    });

    // #217: a feed made from a saved search holds its top 500 matches, and
    // its card in Settings says how many there are, so one past the cap
    // shows the trimmed note as the other feeds do.
    scenario('search: a published search feed over its cap reports its matches', function () use ($db, $make, $search): void {
        $u = freshUser($db);
        $first = $make($u, 'Sample search night 0', '2027-01-01');
        $make($u, 'Alex Example lunch', '2027-01-02');
        $template = $db->one('SELECT * FROM events WHERE id = ?', [$first]);
        unset($template['id']);
        $cols = array_keys($template);
        $rowSql = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $params = [];
        for ($i = 1; $i <= OutFeeds::SEARCH_LIMIT + 2; $i++) {
            $day = (new DateTimeImmutable('2027-01-01 18:00:00'))->modify('+' . $i . ' days');
            $row = ['uid' => 'search-feed-sample-' . $i . '@example.test', 'title' => 'Sample search night ' . $i,
                'start_utc' => $day->format('Y-m-d H:i:s'), 'end_utc' => $day->modify('+1 hour')->format('Y-m-d H:i:s')] + $template;
            foreach ($cols as $c) {
                $params[] = $row[$c];
            }
        }
        $db->run('INSERT INTO events (`' . implode('`, `', $cols) . '`) VALUES ' . implode(', ', array_fill(0, OutFeeds::SEARCH_LIMIT + 2, $rowSql)), $params);
        $matches = OutFeeds::SEARCH_LIMIT + 3;

        $secret = str_repeat('s', 32);
        $feeds = new OutFeeds($db, $search, ['base_url' => 'https://calendar.example.test', 'session_secret' => $secret]);
        $tokens = [];
        foreach (['Sample search' => 'search night', 'Sample lunch search' => 'lunch'] as $name => $q) {
            $tokens[$name] = 'sample-feed-token-' . bin2hex(random_bytes(8));
            $protected = FeedCredentials::protectOutboundToken($tokens[$name], $secret);
            $db->insert('out_feeds', ['user_id' => $u, 'token' => $protected['hash'], 'token_sealed' => $protected['sealed'],
                'name' => $name, 'scope_json' => json_encode(['type' => 'search', 'q' => $q])]);
        }
        $size = [];
        foreach ($feeds->listAll($u) as $f) {
            $size[$f['name']] = [$f['eventCount'], $f['eventLimit']];
        }
        checkEq('search feed: the card counts every match, past the cap of the feed', [$matches, OutFeeds::SEARCH_LIMIT], $size['Sample search']);
        checkEq('search feed: the count is the search\'s own', $matches, $search->count($u, 'search night'));
        checkEq('search feed: one under the cap counts what it holds', [1, OutFeeds::SEARCH_LIMIT], $size['Sample lunch search']);
        checkEq('search feed: the feed itself holds the cap', OutFeeds::SEARCH_LIMIT,
            substr_count((string) $feeds->renderByToken($tokens['Sample search']), 'BEGIN:VEVENT'));
    });
};
