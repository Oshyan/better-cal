<?php

declare(strict_types=1);

// Two things the worker does with the model, checked against the real schema.
// Mail this app sent itself (its header, EmailSender::APP_HEADER) arriving in
// the mailbox the ingest reads is skipped before any tier, and logged as the
// app's own; the same message without the header is read as before. And a
// model call the provider refuses says why: the job error carries the
// provider's status and message, a billing or quota refusal holds a Review
// notice with it, a malformed reply says something else, and no message
// carries the key.

use BetterCal\Domain\MailAdmission;
use BetterCal\Domain\MailIngest;
use BetterCal\Domain\ModelAdmission;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmFailure;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\LlmTransport;

return static function (Db $db): void {
    $key = 'sample-secret-key-0123456789';
    $fake = new class implements LlmTransport {
        public string|LlmFailure|null $reply = null;
        public int $calls = 0;

        public function post(string $url, array $headers, string $body, int $timeoutSeconds): string|LlmFailure|null
        {
            $this->calls++;
            return $this->reply;
        }
    };
    $gateway = new LlmGateway(['gemini' => ['key' => $key, 'model' => 'gemini-sample']], $fake);
    $refused = static fn(): LlmFailure => LlmFailure::fromResponse(402, (string) json_encode(['error' => [
        'code' => 402, 'status' => 'RESOURCE_EXHAUSTED',
        'message' => 'Your prepayment credits are depleted. Please add credits to continue.',
    ]]));
    $d = domains($db);

    scenario('own mail: skipped before any tier, and the same mail without the mark is read as before', function () use ($db, $d, $gateway, $fake, $refused): void {
        $u = freshUser($db);
        $mail = new MailIngest($db, $d->events, $gateway, new MailAdmission($db));
        // Eventish subject and a date in the body: what took an alert to the model tier.
        $alert = static fn(string $id, array $more = []): array => $more + [
            'messageId' => $id, 'subject' => 'Sample-App: Event ranking has been failing', 'from' => 'owner@example.test',
            'icsParts' => [], 'html' => null, 'text' => "These have been failing:\n- Event ranking: failing since Nov 5, 2026 at 9:00 AM",
        ];
        $fake->reply = $refused();
        $fake->calls = 0;

        $own = $mail->ingestMessage($u, $alert('own-alert@example.test', ['appMail' => 'alert']), 'UTC');
        checkEq('own mail: the app\'s own message is skipped, no tier, saying it was the app\'s own',
            ['none', 'skipped', MailIngest::OWN_MAIL], [$own['tier'], $own['outcome'], $own['error']]);
        checkEq('own mail: no model call was made for it', 0, $fake->calls);
        checkEq('own mail: mail_ingest records it as skipped with that reason', ['none', 'skipped', MailIngest::OWN_MAIL],
            array_values($db->one('SELECT tier, outcome, error FROM mail_ingest WHERE message_id = ?', ['own-alert@example.test'])));
        checkEq('own mail: nothing in Review for it', 0, (int) $db->scalar('SELECT COUNT(*) FROM review_items WHERE user_id = ?', [$u]));

        $other = $mail->ingestMessage($u, $alert('not-marked@example.test'), 'UTC');
        checkEq('own mail: without the header the message reaches the model tier as before', ['llm', 'error', 1], [$other['tier'], $other['outcome'], $fake->calls]);
        check('own mail: and its error says what the provider answered', str_contains((string) $other['error'], 'Gemini answered 402 RESOURCE_EXHAUSTED: Your prepayment credits are depleted'), (string) $other['error']);
        checkEq('own mail: that one is an unreadable-email notice, as before', 1,
            (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND kind = 'mail_error'", [$u]));
    });

    scenario('model refusals: the job error and Review carry the provider\'s reason', function () use ($db, $d, $gateway, $fake, $refused, $key): void {
        $u = freshUser($db);
        $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $calendarName = (string) $db->scalar('SELECT name FROM calendars WHERE id = ?', [$home]);
        for ($i = 1; $i <= 6; $i++) {
            $id = $db->insert('events', ['user_id' => $u, 'calendar_id' => $home, 'uid' => "rank-sample-$i@example.test", 'title' => "Sample talk $i",
                'start_utc' => "2030-01-0{$i} 18:00:00", 'end_utc' => "2030-01-0{$i} 19:00:00", 'tzid' => 'UTC', 'source' => 'feed']);
            if ($i <= 5) {
                $db->insert('feedback_signals', ['user_id' => $u, 'event_id' => $id, 'kind' => $i % 2 === 0 ? 'up' : 'down']);
            }
        }
        $review = new ReviewQueue($db, $d->events);
        $ranking = new Ranking($db, $gateway, null, new ModelAdmission($db), $review);
        $run = static function () use ($ranking): ?string {
            try {
                $ranking->run();
                return null;
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
        };

        $fake->reply = $refused();
        $billing = (string) $run();
        check('model refusals: a 402 puts the provider\'s status and message in the job error',
            str_contains($billing, 'events left unscored: Gemini answered 402 RESOURCE_EXHAUSTED: Your prepayment credits are depleted.'), $billing);
        $item = $db->one("SELECT title, summary, payload_json FROM review_items WHERE user_id = ? AND kind = ? AND status = 'open'", [$u, ReviewQueue::KIND_MODEL_LIMIT]);
        check('model refusals: a billing refusal holds a ranking notice in Review', $item !== null && $item['title'] === 'Event ranking paused');
        check('model refusals: the notice carries the provider\'s message', str_contains((string) ($item['summary'] ?? ''), 'Your prepayment credits are depleted'), (string) ($item['summary'] ?? ''));
        checkEq('model refusals: the notice is marked as the provider refusing, for its calendar',
            ['ranking_provider_refused', $calendarName], [json_decode((string) ($item['payload_json'] ?? '{}'), true)['reason'] ?? null, json_decode((string) ($item['payload_json'] ?? '{}'), true)['calendarName'] ?? null]);

        $fake->reply = (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]]]);
        $malformed = (string) $run();
        check('model refusals: a 200 with a malformed body says so instead',
            str_contains($malformed, 'Gemini answered, but its reply was not the JSON asked for.') && !str_contains($malformed, '402'), $malformed);
        checkEq('model refusals: a malformed reply is not a billing notice', 1,
            (int) $db->scalar('SELECT COUNT(*) FROM review_items WHERE user_id = ? AND kind = ?', [$u, ReviewQueue::KIND_MODEL_LIMIT]));

        $texts = [$billing, $malformed, (string) ($item['summary'] ?? ''), (string) ($item['payload_json'] ?? '')];
        check('model refusals: no message carries the key', array_filter($texts, static fn(string $t): bool => str_contains($t, $key)) === []);
    });
};
