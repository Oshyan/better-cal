<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Domain\Sanitize;
use BetterCal\Support\Limits;
use Webklex\PHPIMAP\IMAP;

/**
 * IMAP transport for the mail ingest worker: fetches unseen messages from
 * the calendar@ mailbox and decomposes each into the shape MailIngest
 * expects, marking them seen afterwards. webklex/php-imap runs its own
 * protocol client, so no ext-imap is needed. All webklex usage stays inside
 * this class; the domain logic is testable without it.
 *
 * Anyone can send mail to the ingest address, so nothing about a message is
 * trusted until it has been sized (BC-10):
 *
 * - Size before headers. An IMAP UID search and RFC822.SIZE fetch happen before
 *   a Message object exists. One over Limits::MAIL_BYTES is marked seen and
 *   reported as skipped without decoding Subject, From, or Message-ID.
 * - One at a time. This is a generator: a body is downloaded, handed to the
 *   caller and dropped before the next one is fetched, instead of ten fully
 *   decoded messages sitting in an array.
 * - No poison messages. $begin is called (and the message marked seen) BEFORE
 *   its body is downloaded and parsed. If that parse kills the worker outright
 *   (out of memory is not an exception, nothing can catch it), the message is
 *   already seen and already recorded as started, so the next run does not
 *   walk into it again. An ordinary failure undoes both, so it is retried.
 * - Parts have budgets too: a calendar part over MAIL_ICS_BYTES is ignored, and
 *   text/html is cut to MAIL_BODY_CHARS before anything downstream reads it.
 */
final class MailFetcher
{
    public function __construct(private readonly array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        return ($this->cfg['imap']['host'] ?? '') !== '' && ($this->cfg['imap']['user'] ?? '') !== '';
    }

    /**
     * @param ?callable(string):bool $begin called with the stable IMAP transport
     *        key before any display header is decoded
     * @param ?callable(string,array{messageId:string,subject:string,from:string}):bool $identify
     *        attaches bounded RFC header identity; false means Message-ID replay
     * @param ?callable(string):void $abort called with the transport key after a retryable failure
     * @return \Generator<int, array{transportKey:string,messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string,oversize?:int}>
     */
    public function fetchUnseen(
        int $limit = 10,
        ?callable $begin = null,
        ?callable $identify = null,
        ?callable $abort = null,
    ): \Generator
    {
        if (!$this->isConfigured() || !class_exists(\Webklex\PHPIMAP\ClientManager::class)) {
            return;
        }
        $manager = new \Webklex\PHPIMAP\ClientManager();
        $client = $manager->make([
            'host' => $this->cfg['imap']['host'],
            'port' => (int) ($this->cfg['imap']['port'] ?? 993),
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->cfg['imap']['user'],
            'password' => $this->cfg['imap']['pass'],
            'protocol' => 'imap',
        ]);
        $client->connect();
        try {
            $inbox = $client->getFolder('INBOX');
            $query = $inbox->messages()
                ->unseen()
                ->setFetchBody(false)
                ->leaveUnread()
                ->setSequence(IMAP::ST_UID);
            // search() returns only integer UIDs. Query::get() is deliberately
            // not used: Webklex batch-fetches and parses every RFC822 header in
            // get(), even when setFetchBody(false) is set.
            $uids = array_slice(array_values($query->search()->toArray()), 0, max(0, $limit));
            if ($uids === []) {
                return;
            }
            $status = $inbox->status();
            if (!isset($status['uidvalidity']) && !isset($status['UIDVALIDITY'])) {
                throw new \RuntimeException('IMAP did not report UIDVALIDITY for INBOX');
            }
            $uidValidity = (string) ($status['uidvalidity'] ?? $status['UIDVALIDITY']);
            $connection = $client->getConnection();
            $sizes = $connection->sizes($uids, IMAP::ST_UID)->validatedData();
            $mailbox = (string) ($this->cfg['imap']['host'] ?? '') . "\0"
                . (string) ($this->cfg['imap']['user'] ?? '') . "\0INBOX\0" . $uidValidity;
            $candidates = (static function () use ($uids, $sizes, $mailbox, $connection, $query): \Generator {
                foreach ($uids as $uid) {
                    $uid = (int) $uid;
                    if (!array_key_exists($uid, $sizes) && !array_key_exists((string) $uid, $sizes)) {
                        throw new \RuntimeException("IMAP did not report a size for UID $uid");
                    }
                    $size = (int) ($sizes[$uid] ?? $sizes[(string) $uid]);
                    yield [
                        'transportKey' => 'imap-' . hash('sha256', $mailbox . "\0" . $uid),
                        'size' => $size,
                        'load' => static fn(): object => $query->getMessageByUid($uid),
                        'markSeen' => static fn() => $connection->store(['\\Seen'], $uid, $uid, '+', true, IMAP::ST_UID)->validatedData(),
                        'markUnseen' => static fn() => $connection->store(['\\Seen'], $uid, $uid, '-', true, IMAP::ST_UID)->validatedData(),
                    ];
                }
            })();
            yield from $this->processCandidates($candidates, $begin, $identify, $abort);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Testable security boundary between cheap IMAP metadata and header/body
     * materialization. Candidate closures keep Webklex details out of tests.
     *
     * @param iterable<array{transportKey:string,size:int,load:callable():object,markSeen:callable():mixed,markUnseen:callable():mixed}> $candidates
     * @return \Generator<int, array{transportKey:string,messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string,oversize?:int}>
     */
    public function processCandidates(
        iterable $candidates,
        ?callable $begin = null,
        ?callable $identify = null,
        ?callable $abort = null,
    ): \Generator {
        foreach ($candidates as $candidate) {
            $key = $candidate['transportKey'];
            $begun = false;
            try {
                if ($begin !== null && $begin($key) === false) {
                    ($candidate['markSeen'])();
                    continue;
                }
                $begun = true;
                // Seen BEFORE header/body materialization: a fatal leaves the
                // durable started row and prevents the next run retrying it.
                ($candidate['markSeen'])();
                $size = (int) $candidate['size'];
                if ($size > Limits::get('MAIL_BYTES')) {
                    yield [
                        'transportKey' => $key,
                        'messageId' => $key,
                        'subject' => '',
                        'from' => '',
                        'icsParts' => [],
                        'html' => null,
                        'text' => null,
                        'oversize' => $size,
                    ];
                    continue;
                }
                try {
                    $message = ($candidate['load'])();
                    $envelope = self::envelope($message);
                    if ($identify !== null && $identify($key, $envelope) === false) {
                        continue;
                    }
                    $message->parseBody();
                    $result = ['transportKey' => $key] + $this->decompose($message, $envelope);
                } finally {
                    // A Webklex Message and its Attachment objects point back
                    // to each other. Drop that cycle before suspending this
                    // generator at yield, or decoded messages accumulate for
                    // the whole batch even though their returned data is flat.
                    unset($message);
                    gc_collect_cycles();
                }
                yield $result;
            } catch (\Throwable $e) {
                // Caught means survivable (a dropped connection, malformed
                // header/part): put it back so the next run may try again.
                error_log('mail ingest fetch failed: ' . $e->getMessage());
                try {
                    ($candidate['markUnseen'])();
                } catch (\Throwable) {
                }
                if ($begun && $abort !== null) {
                    $abort($key);
                }
            }
        }
    }

    /** @return array{messageId:string,subject:string,from:string} from headers alone */
    private static function envelope(object $message): array
    {
        $messageId = trim((string) $message->getMessageId(), " \t<>");
        if ($messageId === '') {
            $messageId = 'no-id-' . sha1(
                mb_substr((string) $message->getSubject(), 0, 500)
                . mb_substr((string) $message->getDate(), 0, 200)
            );
        }
        $fromAttr = $message->getFrom();
        $from = '';
        if ($fromAttr && isset($fromAttr[0])) {
            $from = mb_substr(strtolower((string) ($fromAttr[0]->mail ?? '')), 0, 255);
        }
        return [
            'messageId' => mb_substr($messageId, 0, 255),
            'subject' => mb_substr((string) $message->getSubject(), 0, 500),
            'from' => $from,
        ];
    }

    /**
     * @param array{messageId:string,subject:string,from:string} $envelope
     * @return array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string}
     */
    private function decompose(object $message, array $envelope): array
    {
        $maxIcs = Limits::get('MAIL_ICS_BYTES');
        $icsParts = [];
        foreach ($message->getAttachments() as $attachment) {
            $name = strtolower((string) $attachment->getName());
            $mime = strtolower((string) $attachment->getMimeType());
            if (!str_ends_with($name, '.ics') && !str_contains($mime, 'text/calendar')) {
                continue;
            }
            $content = (string) $attachment->getContent();
            if (strlen($content) <= $maxIcs) {
                $icsParts[] = $content;
            } else {
                error_log('mail ingest: ignored a ' . strlen($content) . ' byte calendar part in ' . $envelope['messageId']);
            }
        }
        // Inline text/calendar bodies (Google sends invites this way too).
        $raw = (string) $message->getRawBody();
        if ($icsParts === [] && stripos($raw, 'BEGIN:VCALENDAR') !== false) {
            // strpos, not a lazy regex: many BEGINs without an END made the
            // regex rescan to the end from each one (review of F9).
            $decoded = quoted_printable_decode($raw);
            $b = stripos($decoded, 'BEGIN:VCALENDAR');
            $e = $b === false ? false : stripos($decoded, 'END:VCALENDAR', $b);
            if ($b !== false && $e !== false && $e + 13 - $b <= $maxIcs) {
                $icsParts[] = substr($decoded, $b, $e + 13 - $b);
            }
        }
        unset($raw);

        $html = $message->hasHTMLBody() ? self::clip((string) $message->getHTMLBody()) : null;
        $text = $message->hasTextBody() ? self::clip((string) $message->getTextBody()) : null;
        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags(Sanitize::dropScriptStyle($html))));
        }

        return $envelope + ['icsParts' => $icsParts, 'html' => $html, 'text' => $text];
    }

    /** Bodies feed markup extraction and an LLM prompt; neither needs more than this. */
    private static function clip(string $body): string
    {
        $max = Limits::get('MAIL_BODY_CHARS');
        return strlen($body) > $max ? mb_strcut($body, 0, $max) : $body;
    }
}
