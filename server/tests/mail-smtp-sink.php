<?php
declare(strict_types=1);

// A loopback-only SMTP sink with a deterministic barrier at DATA completion.
if (PHP_SAPI !== 'cli' || getenv('BC_MAIL_DISPOSABLE') !== '1') exit(2);
require __DIR__ . '/../src/bootstrap.php';
use BetterCal\Infra\{Db, EmailSender};
use BetterCal\Domain\Rsvp;

$context = stream_context_create(['ssl' => ['local_cert' => '/tmp/bc-mail/cert.pem', 'local_pk' => '/tmp/bc-mail/key.pem']]);
$server = stream_socket_server('ssl://127.0.0.1:465', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) throw new RuntimeException($error);
echo "ready\n";
fflush(STDOUT);
$socket = stream_socket_accept($server, 20);
if (!$socket) throw new RuntimeException('No SMTP client');
stream_set_timeout($socket, 20);
fwrite($socket, "220 localhost test sink\r\n");
$evidence = [];
while (($line = fgets($socket)) !== false) {
    $command = strtoupper(strtok(trim($line), ' '));
    if (in_array($command, ['EHLO', 'HELO'], true)) fwrite($socket, "250 localhost\r\n");
    elseif ($command === 'MAIL' || $command === 'RCPT') {
        if (!str_contains($line, '@example.test>')) throw new RuntimeException('Only invented recipients are allowed');
        fwrite($socket, "250 OK\r\n");
    } elseif ($command === 'DATA') {
        fwrite($socket, "354 end with dot\r\n");
        $body = '';
        while (($line = fgets($socket)) !== false && $line !== ".\r\n") $body .= $line;
        $db = new Db(['dsn' => 'mysql:host=bc-mail-db;dbname=mail_test', 'user' => 'sample', 'pass' => 'synthetic-only']);
        $cfg = ['smtp' => ['host' => 'localhost', 'port' => 465, 'from' => 'sam@example.test']];
        try {
            (new Rsvp($db, new EmailSender($cfg), $cfg))->answer(1, (int) $argv[1], 'accepted', $argv[2]);
            $evidence['concurrent'] = 'unsafe';
        } catch (BetterCal\Http\HttpError $e) { $evidence['concurrent'] = $e->errorCode; }
        $invite = json_decode($db->scalar('SELECT invite_json FROM events WHERE id=?', [(int) $argv[1]]), true);
        $evidence['durable'] = $invite['reply']['reason'] ?? null;
        $evidence['reserved'] = (int) $db->scalar('SELECT COUNT(*) FROM external_action_admissions');
        $evidence['wireReply'] = str_contains($body, 'METHOD:REPLY');
        // There must be no transaction-held event lock across SMTP. A later
        // edit must also survive completion of the reply's local bookkeeping.
        $invite['subject'] = 'Fresh sample metadata';
        $db->run('UPDATE events SET invite_json=? WHERE id=?', [json_encode($invite), (int) $argv[1]]);
        if (($argv[3] ?? '') === 'lose-ack') break;
        fwrite($socket, "250 accepted by test sink\r\n");
    } elseif ($command === 'QUIT') {
        fwrite($socket, "221 goodbye\r\n");
        break;
    } else fwrite($socket, "250 OK\r\n");
}
fclose($socket);
fclose($server);
echo json_encode($evidence) . "\n";
