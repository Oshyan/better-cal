<?php

declare(strict_types=1);

// Ranking's runs go round the accounts: each run has a few model calls, and
// it spends them on the least recently ranked account first, so an account
// whose events never get scored cannot keep every other account waiting.
// Every name is invented.

use BetterCal\Domain\ModelAdmission;
use BetterCal\Domain\Ranking;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmFailure;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\LlmTransport;

return static function (Db $db): void {
    // An account with signals and $count future feed events, on one calendar.
    $account = static function (int $count) use ($db): array {
        $u = freshUser($db);
        $cal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $day = (new \DateTimeImmutable('2031-01-01 18:00:00', new \DateTimeZone('UTC')))->modify("+$i days");
            $ids[] = (int) $db->insert('events', ['user_id' => $u, 'calendar_id' => $cal, 'uid' => "rank-rotation-$u-$i@example.test", 'title' => "Sample lecture $i",
                'start_utc' => $day->format('Y-m-d H:i:s'), 'end_utc' => $day->modify('+1 hour')->format('Y-m-d H:i:s'), 'tzid' => 'UTC', 'source' => 'feed']);
        }
        for ($i = 0; $i < Ranking::MIN_SIGNALS; $i++) {
            $db->insert('feedback_signals', ['user_id' => $u, 'event_id' => $ids[$i], 'kind' => $i % 2 === 0 ? 'up' : 'down']);
        }
        return [$u, $ids];
    };

    scenario('ranking: an account whose events never get scored does not keep the next account waiting', function () use ($db, $account): void {
        // The first has more stale events than one run's calls can reach.
        [$stuck] = $account(Ranking::BATCH_SIZE * 5);
        [$waiting, $waitingIds] = $account(Ranking::MIN_SIGNALS + 1);
        // A model that scores only the second account's events: the first's
        // batches come back with nothing for them, every time.
        $fake = new class ($waitingIds) implements LlmTransport {
            public function __construct(private readonly array $ids)
            {
            }

            public function post(string $url, array $headers, string $body, int $timeoutSeconds): string|LlmFailure|null
            {
                $results = array_map(static fn(int $id): array => ['eventId' => $id, 'score' => 0.6], $this->ids);
                return (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['results' => $results])]]]]]]);
            }
        };
        $ranking = new Ranking($db, new LlmGateway(['gemini' => ['key' => 'sample-secret-key-0123456789', 'model' => 'gemini-sample']], $fake), null, new ModelAdmission($db));
        $ranked = static fn(int $u): int => (int) $db->scalar("SELECT COUNT(*) FROM model_admissions WHERE user_id = ? AND operation = 'ranking'", [$u]);
        $scored = static fn(int $u): int => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND scored_at IS NOT NULL', [$u]);

        // Run until the stuck account has had its turn (the first run on a
        // database of this file alone; accounts left by other files go round too).
        $runs = 0;
        while ($ranked($stuck) === 0 && $runs < 20) {
            $ranking->run();
            $runs++;
        }
        check('ranking: the stuck account had a turn', $ranked($stuck) > 0, "after $runs runs");
        checkEq('ranking: that turn used the whole run and scored none of its events', 0, $scored($stuck));
        $ranking->run();
        checkEq('ranking: the next run reaches the waiting account and scores its events', Ranking::MIN_SIGNALS + 1, $scored($waiting));
    });
};
