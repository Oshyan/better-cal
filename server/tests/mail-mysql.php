<?php
declare(strict_types=1);

// Only the dedicated disposable database, IMAP mailbox and loopback SMTP sink.
if (PHP_SAPI !== 'cli' || getenv('BC_MAIL_DISPOSABLE') !== '1') exit(2);
putenv('BETTERCAL_SESSION_SECRET=synthetic-mail-secret');
require __DIR__ . '/../src/bootstrap.php';
use BetterCal\Infra\{Db, Migrator, EmailSender, MailFetcher, JobQueue, LlmGateway, LlmTransport};
use BetterCal\Domain\{Auth, Calendars, Events, Filters, Labels, MailIngest, ReviewQueue, Recurrence, Rsvp, Trips, Undo};
use BetterCal\Support\Limits;

$dbCfg = ['dsn' => 'mysql:host=bc-mail-db;dbname=mail_test', 'user' => 'sample', 'pass' => 'synthetic-only'];
$db = new Db($dbCfg);
if ((int) $db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') !== 0) throw new RuntimeException('Requires an empty test database');
(new Migrator($db, __DIR__ . '/../migrations'))->run(static function () {});
$checks = 0;
function verifyMail(string $name, bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
    echo "PASS: $name\n";
}
$cfg = ['session_secret' => 'synthetic-mail-secret', 'smtp' => ['host' => 'localhost', 'port' => 465, 'from' => 'sam@example.test'], 'imap' => ['host' => 'localhost', 'port' => 993, 'user' => 'sample', 'pass' => 'synthetic-only']];
$db->insert('users', ['email' => 'sam@example.test', 'password_hash' => password_hash('sample-password', PASSWORD_DEFAULT)]);
$session = (new Auth($db, $cfg))->login('sam@example.test', 'sample-password')['token'];
$undo = new Undo($db);
$events = new Events($db, new Recurrence(), $undo, new Labels($db), new Filters($db, $undo, new JobQueue($db)), new Trips($db, $undo));
$queue = new ReviewQueue($db, $events);
$transport = new class implements LlmTransport {
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): ?string {
        return json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['title' => 'Sample booking', 'start' => '2090-01-01T10:00:00', 'end' => '2090-01-01T11:00:00', 'allDay' => false, 'location' => '', 'timezone' => 'UTC'])]]]]]]);
    }
};
$mail = new MailIngest($db, $events, new LlmGateway(['gemini' => ['key' => 'synthetic', 'model' => 'synthetic']], $transport));
$ics = static fn(string $uid, string $method): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sample//EN\r\nMETHOD:$method\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20900101T100000Z\r\nDTEND:20900101T110000Z\r\nSUMMARY:Sample event\r\nORGANIZER:mailto:bookings@example.test\r\nATTENDEE:mailto:sam@example.test\r\nSEQUENCE:1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
foreach (['review', 'approved', 'unknown', 'any'] as $mode) {
    foreach (['REQUEST', 'PUBLISH', 'markup', 'gcal-link', 'llm'] as $tier) {
        $db->run('UPDATE users SET settings_json=? WHERE id=1', [json_encode(['mailEventMode' => $mode === 'unknown' ? 'approved' : $mode, 'mailApprovedSenders' => $mode === 'unknown' ? [] : ['bookings@example.test']])]);
        $message = ['messageId' => "$mode-$tier@example.test", 'subject' => 'Sample booking confirmation', 'from' => 'bookings@example.test', 'icsParts' => [], 'html' => null, 'text' => null];
        if ($tier === 'REQUEST' || $tier === 'PUBLISH') $message['icsParts'] = [$ics("$mode-$tier", $tier)];
        if ($tier === 'markup') $message['html'] = '<script type="application/ld+json">' . json_encode(['@type' => 'Event', 'name' => 'Sample booking', 'startDate' => '2090-01-01T10:00:00Z', 'endDate' => '2090-01-01T11:00:00Z']) . '</script>';
        if ($tier === 'gcal-link') $message['text'] = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=Sample+booking&dates=20900101T100000Z/20900101T110000Z';
        if ($tier === 'llm') $message['text'] = 'Your sample booking is confirmed for January 1, 2090 at 10:00.';
        $result = $mail->ingestMessage(1, $message, 'UTC');
        verifyMail("$mode covers $tier", $result['outcome'] === (in_array($mode, ['review', 'unknown'], true) ? 'held' : 'created'));
        if ($tier === 'REQUEST' && in_array($mode, ['approved', 'any'], true)) {
            $row = $db->one('SELECT status, invite_json FROM events WHERE id=?', [$result['eventId']]);
            verifyMail('automatic invitation is unanswered and sends nothing', $row['status'] === 'tentative' && json_decode($row['invite_json'], true)['myPartstat'] === 'NEEDS-ACTION' && (int) $db->scalar('SELECT COUNT(*) FROM external_action_admissions') === 0);
        }
    }
}
$db->run('UPDATE users SET settings_json=? WHERE id=1', [json_encode(['mailEventMode' => 'approved'])]);
$held = $queue->listFor(1)[0];
$accepted = $queue->accept(1, $held['id'], 'domain');
verifyMail('domain approval adds a held booking without SMTP', $accepted['eventId'] > 0 && (int) $db->scalar('SELECT COUNT(*) FROM external_action_admissions') === 0);
verifyMail('domain approval is stored for other sender addresses', in_array('example.test', json_decode($db->scalar('SELECT settings_json FROM users WHERE id=1'), true)['mailApprovedSenders'], true));
$fields = ['title' => 'Sample invitation', 'start' => '2090-01-01T10:00:00Z', 'end' => '2090-01-01T11:00:00Z', 'allDay' => false, 'tzid' => 'UTC'];
$invite = ['method' => 'REQUEST', 'organizer' => ['email' => 'sam@gmail.com'], 'attendees' => [], 'sequence' => 0];
$sharedId = $queue->holdNewInvitation(1, 'shared-provider-sample', $fields, $invite, 'sam@gmail.com');
$controller = new BetterCal\Http\Controllers\ReviewController($queue, new BetterCal\Domain\Proposals($db, $events, new Trips($db, $undo)), new Calendars($db, $undo, $cfg['session_secret']));
$request = new BetterCal\Http\Request('GET', '/review');
$request->user = ['id' => 1]; $request->authMethod = 'session';
$items = json_decode($controller->index($request)->body, true)['items'];
$sharedCard = array_values(array_filter($items, fn($item) => $item['key'] === 'invite_new:' . $sharedId))[0];
$actions = array_column($sharedCard['actions'], 'label');
verifyMail('Review offers exact address but no shared-provider domain approval', in_array('Always add from sam@gmail.com', $actions, true) && !in_array('Always add from gmail.com', $actions, true));
try { $queue->accept(1, $sharedId, 'domain'); verifyMail('shared-provider domain refused at write boundary', false); }
catch (BetterCal\Http\HttpError $e) { verifyMail('shared-provider domain refused at write boundary', $e->status === 400); }
$queue->accept(1, $sharedId, 'address');
verifyMail('shared-provider exact address approval still works', in_array('sam@gmail.com', json_decode($db->scalar('SELECT settings_json FROM users WHERE id=1'), true)['mailApprovedSenders'], true));

$eventId = (int) $db->scalar("SELECT id FROM events WHERE uid='any-REQUEST'");
foreach ([999 => 'Forged sample revision', 2 => 'Genuine sample revision'] as $sequence => $title) {
    $body = str_replace(['SEQUENCE:1', 'SUMMARY:Sample event'], ['SEQUENCE:' . $sequence, 'SUMMARY:' . $title], $ics('any-REQUEST', 'REQUEST'));
    $result = $mail->ingestMessage(1, ['messageId' => 'revision-' . $sequence . '@example.test', 'subject' => 'Sample change', 'from' => 'bookings@example.test', 'icsParts' => [$body], 'html' => null, 'text' => null], 'UTC');
    verifyMail('both pending sequences remain held', $result['outcome'] === 'held');
}
$genuineId = (int) $db->scalar("SELECT id FROM review_items WHERE kind='invite_change' AND payload_json LIKE '%Genuine sample revision%'");
$queue->accept(1, $genuineId);
verifyMail('genuine lower sequence applies while high pending claim stays visible', $db->scalar('SELECT title FROM events WHERE id=?', [$eventId]) === 'Genuine sample revision' && (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE kind='invite_change' AND status='open' AND payload_json LIKE '%Forged sample revision%'") === 1);

// Twelve invalid calendar bodies before valid mail. The server must advance
// past its first ten unseen messages without a timing-based retry fixture.
foreach (['cur', 'new', 'tmp'] as $part) if (!is_dir('/home/sample/Maildir/' . $part)) mkdir('/home/sample/Maildir/' . $part, 0700, true);
for ($i = 0; $i < 13; $i++) {
    $body = $i < 12 ? "BEGIN:VCALENDAR\r\nBROKEN\r\n" : $ics('imap-valid', 'PUBLISH');
    $raw = "From: bookings@example.test\r\nTo: sam@example.test\r\nSubject: Sample mailbox booking\r\nMessage-ID: <imap-$i@example.test>\r\nDate: Mon, 1 Jan 2090 10:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/calendar; charset=utf-8\r\n\r\n$body";
    $file = '/home/sample/Maildir/new/' . sprintf('%03d.sample', $i);
    file_put_contents($file, $raw); chown($file, 'sample'); chgrp($file, 'sample');
}
foreach (['', '/cur', '/new', '/tmp'] as $part) { chown('/home/sample/Maildir' . $part, 'sample'); chgrp('/home/sample/Maildir' . $part, 'sample'); }
$fetcher = new MailFetcher($cfg);
for ($run = 0; $run < 2; $run++) {
    foreach ($fetcher->fetchUnseen(10, $mail->beginTransport(...), $mail->identifyTransport(...), $mail->abortTransport(...), fn($key, $envelope, $reason) => $mail->failTransport(1, $key, $envelope, $reason)) as $message) $mail->ingestMessage(1, $message, 'UTC');
}
verifyMail('live IMAP reaches valid mail after twelve malformed messages', (int) $db->scalar("SELECT COUNT(*) FROM events WHERE uid='imap-valid'") === 1);
verifyMail('live IMAP unreadable notices retain recoverable headers', (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE kind='mail_error'") === 12 && str_contains($queue->listFor(1)[0]['summary'], 'bookings@example.test'));
$db->run("UPDATE mail_ingest SET processed_at='2000-01-01 00:00:00'");
$mail->pruneReceipts();
verifyMail('live notices survive receipt pruning', (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE kind='mail_error'") === 12 && (int) $db->scalar('SELECT COUNT(*) FROM mail_ingest') === 0);

$eventId = (int) $db->scalar("SELECT id FROM events WHERE uid='any-REQUEST'");
$process = proc_open([PHP_BINARY, __DIR__ . '/mail-smtp-sink.php', (string) $eventId, $session], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!$process || trim(fgets($pipes[1])) !== 'ready') throw new RuntimeException('SMTP sink failed to start');
$rsvp = new Rsvp($db, new EmailSender($cfg), $cfg);
$reply = $rsvp->answer(1, $eventId, 'accepted', $session);
$evidence = json_decode(trim(stream_get_contents($pipes[1])), true);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) fclose($pipe);
if (proc_close($process) !== 0) throw new RuntimeException('SMTP sink: ' . $stderr);
verifyMail('live SMTP delivers to loopback sink', $reply['sent'] && $evidence['wireReply']);
verifyMail('live SMTP barrier sees durable attempt and reserved capacity', $evidence['durable'] === 'sending' && $evidence['reserved'] === 1);
verifyMail('live concurrent identical reply is refused at send barrier', $evidence['concurrent'] === 'rsvp_busy');
verifyMail('reply completion preserves concurrent invitation metadata', json_decode($db->scalar('SELECT invite_json FROM events WHERE id=?', [$eventId]), true)['subject'] === 'Fresh sample metadata');
verifyMail('already sent identical reply does not reserve or resend', $rsvp->answer(1, $eventId, 'accepted', $session)['unchanged'] && (int) $db->scalar('SELECT COUNT(*) FROM external_action_admissions') === 1);
$process = proc_open([PHP_BINARY, __DIR__ . '/mail-smtp-sink.php', (string) $eventId, $session, 'lose-ack'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!$process || trim(fgets($pipes[1])) !== 'ready') throw new RuntimeException('SMTP sink failed to restart');
$uncertain = $rsvp->answer(1, $eventId, 'tentative', $session);
$lost = json_decode(trim(stream_get_contents($pipes[1])), true);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) fclose($pipe);
if (proc_close($process) !== 0) throw new RuntimeException('SMTP sink: ' . $stderr);
verifyMail('lost acknowledgement is uncertain even though sink received reply', $lost['wireReply'] && $uncertain['reason'] === 'uncertain');
try { $rsvp->answer(1, $eventId, 'tentative', $session); verifyMail('lost acknowledgement cannot silently resend', false); }
catch (BetterCal\Http\HttpError $e) { verifyMail('lost acknowledgement cannot silently resend', $e->errorCode === 'rsvp_uncertain'); }
Limits::configure(['NOTIFY_EMAILS_PER_ACCOUNT_HOUR' => 2]);
verifyMail('explicit retry still respects persistent outbound admission', $rsvp->answer(1, $eventId, 'declined', $session, true)['reason'] === 'email_limit');
Limits::reset();
$invite = json_decode($db->scalar('SELECT invite_json FROM events WHERE id=?', [$eventId]), true);
$invite['reply']['reason'] = 'sending';
$db->run('UPDATE events SET invite_json=? WHERE id=?', [json_encode($invite), $eventId]);
try { $rsvp->answer(1, $eventId, 'declined', $session); verifyMail('uncertain attempt requires explicit confirmation', false); }
catch (BetterCal\Http\HttpError $e) { verifyMail('uncertain attempt requires explicit confirmation', $e->errorCode === 'rsvp_uncertain'); }
echo "$checks live checks passed\n";
