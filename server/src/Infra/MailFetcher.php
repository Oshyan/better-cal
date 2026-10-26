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
 * - A bounded list of unread UIDs (#157). Anyone who can send to the address
 *   sets the unread count, so a large one is searched a window of UIDs at a
 *   time, oldest first, only as far as this run gets (unseenUids).
 */
final class MailFetcher
{
    /** Up to this many unread messages, one search lists them all, as before. */
    public const SEARCH_ALL_MAX = 5000;
    /** Past it, each search covers this many UIDs to begin with... */
    public const UID_WINDOW = 2000;
    /** ...widening while searches come back nearly empty, up to this many. */
    public const UID_WINDOW_MAX = 128000;

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
        ?callable $failed = null,
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
            $status = $inbox->status();
            $unseen = isset($status['unseen']) ? (int) $status['unseen'] : null;
            if ($unseen === 0) {
                return;
            }
            if (!isset($status['uidvalidity']) && !isset($status['UIDVALIDITY'])) {
                throw new \RuntimeException('IMAP did not report UIDVALIDITY for INBOX');
            }
            $uidValidity = (string) ($status['uidvalidity'] ?? $status['UIDVALIDITY']);
            $connection = $client->getConnection();
            // search() returns only integer UIDs. Query::get() is deliberately
            // not used: Webklex batch-fetches and parses every RFC822 header in
            // get(), even when setFetchBody(false) is set.
            $uids = self::unseenUids(
                $unseen,
                (int) ($status['uidnext'] ?? 0),
                static fn(): array => array_values($query->search()->toArray()),
                static fn(string $set): array => (array) $connection->search(['UNSEEN', 'UID', $set], IMAP::ST_UID)->validatedData(),
            );
            $mailbox = (string) ($this->cfg['imap']['host'] ?? '') . "\0"
                . (string) ($this->cfg['imap']['user'] ?? '') . "\0INBOX\0" . $uidValidity;
            $candidates = (static function () use ($uids, $mailbox, $connection, $query): \Generator {
                foreach ($uids as $uid) {
                    $uid = (int) $uid;
                    yield [
                        'transportKey' => 'imap-' . hash('sha256', $mailbox . "\0" . $uid),
                        // Only fresh work fetches metadata or materializes a
                        // message. Previously handled unseen UIDs do not own
                        // the ten-message work budget.
                        'size' => static function () use ($connection, $uid): int {
                            $sizes = $connection->sizes([$uid], IMAP::ST_UID)->validatedData();
                            if (!isset($sizes[$uid])) throw new \RuntimeException('IMAP did not report a message size');
                            return (int) $sizes[$uid];
                        },
                        'load' => static fn(): object => $query->getMessageByUid($uid),
                        'markSeen' => static fn() => $connection->store(['\\Seen'], $uid, $uid, '+', true, IMAP::ST_UID)->validatedData(),
                        'markUnseen' => static fn() => $connection->store(['\\Seen'], $uid, $uid, '-', true, IMAP::ST_UID)->validatedData(),
                    ];
                }
            })();
            yield from $this->processCandidates($candidates, $begin, $identify, $abort, $failed, max(0, $limit));
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Unread UIDs, oldest first, holding at most UID_WINDOW_MAX (or
     * SEARCH_ALL_MAX) of them at a time. A normal unread count is one search,
     * as it always was. A large one, or none reported, is searched in windows
     * of UIDs from the first, and only as far as the caller keeps reading: a
     * run that stops after ten messages never lists the rest. A window that
     * comes back nearly empty makes the next one wider, so a mailbox whose
     * UIDs run into the millions (Gmail) isn't crossed 2000 at a time. Nothing is remembered
     * between runs, so a message marked unread again for a retry is found
     * the same way as any other. Pure apart from the two searches; tested.
     *
     * @param callable():array $searchAll every unread UID
     * @param callable(string):array $searchRange unread UIDs within "a:b"
     * @return \Generator<int,int>
     */
    public static function unseenUids(?int $unseenCount, int $uidNext, callable $searchAll, callable $searchRange): \Generator
    {
        if (($unseenCount !== null && $unseenCount <= self::SEARCH_ALL_MAX) || $uidNext <= 1) {
            $all = array_map('intval', $searchAll());
            sort($all, SORT_NUMERIC);
            yield from $all;
            return;
        }
        $width = self::UID_WINDOW;
        for ($low = 1; $low < $uidNext; $low = $high + 1) {
            $high = min($low + $width - 1, $uidNext - 1);
            $found = array_map('intval', $searchRange($low . ':' . $high));
            sort($found, SORT_NUMERIC);
            foreach ($found as $uid) {
                if ($uid >= $low && $uid <= $high) yield $uid;
            }
            $width = count($found) < intdiv(self::UID_WINDOW, 4) ? min($width * 8, self::UID_WINDOW_MAX) : self::UID_WINDOW;
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
        ?callable $failed = null,
        int $limit = PHP_INT_MAX,
    ): \Generator {
        $attempted = 0;
        foreach ($candidates as $candidate) {
            if ($attempted >= $limit) break;
            $key = $candidate['transportKey'];
            $begun = false;
            $envelope = ['messageId' => $key, 'subject' => '', 'from' => ''];
            try {
                if ($begin !== null && $begin($key) === false) {
                    try { ($candidate['markSeen'])(); } catch (\Throwable) { /* Durable checkpoint still excludes it. */ }
                    continue;
                }
                $begun = true;
                $attempted++;
                // Seen BEFORE header/body materialization: a fatal leaves the
                // durable started row and prevents the next run retrying it.
                try { ($candidate['markSeen'])(); } catch (\Throwable) { /* The checkpoint is authoritative. */ }
                $size = is_callable($candidate['size']) ? ($candidate['size'])() : (int) $candidate['size'];
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
                if (!$begun || $e instanceof \PDOException) throw $e;
                if (self::retryableTransportFailure($e)) {
                    $restored = false;
                    try {
                        ($candidate['markUnseen'])();
                        $restored = true;
                    } catch (\Throwable) {
                        // The message might still be Seen. Keep its checkpoint
                        // and notice instead of silently losing the retry.
                    }
                    if ($restored) {
                        if ($abort !== null) $abort($key);
                        throw $e; // A transport failure is a retryable job failure.
                    }
                }
                $reason = 'Message could not be decoded (' . (new \ReflectionClass($e))->getShortName() . ').';
                error_log('mail ingest: ' . $reason);
                if ($failed !== null) $failed($key, $envelope, $reason);
            }
        }
    }

    public static function retryableTransportFailure(\Throwable $error): bool
    {
        do {
            if ($error instanceof \Webklex\PHPIMAP\Exceptions\ConnectionFailedException
                || $error instanceof \Webklex\PHPIMAP\Exceptions\ImapServerErrorException
                || $error instanceof \Webklex\PHPIMAP\Exceptions\ResponseException) return true;
        } while (($error = $error->getPrevious()) !== null);
        return false;
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
                throw new \UnexpectedValueException('Calendar attachment exceeds the safe parsing size');
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
            } else throw new \UnexpectedValueException('Incomplete or oversized calendar body');
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
