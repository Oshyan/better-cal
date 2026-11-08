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
 *   sets the unread count, so a large one is searched a window of message
 *   positions at a time, oldest first, only as far as this run gets, and
 *   never more often than the mailbox's size allows (unseenUids).
 */
final class MailFetcher
{
    /** Up to this many unread messages, one search lists them all, as before. */
    public const SEARCH_ALL_MAX = 5000;
    /** Past it, each search covers this many message positions... */
    public const POSITION_WINDOW = 2000;
    /** ...and a run makes at most this many: a mailbox of a million messages, crossed once. */
    public const SEARCHES_MAX = 500;

    /** The mailbox read: always the inbox, nothing configures another. */
    private const FOLDER = 'INBOX';

    /**
     * @param ?\Closure(array):\Webklex\PHPIMAP\Client $makeClient builds the
     *        IMAP client from its settings; tests pass one that talks to a
     *        scripted server instead of a socket
     */
    public function __construct(private readonly array $cfg, private readonly ?\Closure $makeClient = null)
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
        $settings = [
            'host' => $this->cfg['imap']['host'],
            'port' => (int) ($this->cfg['imap']['port'] ?? 993),
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->cfg['imap']['user'],
            'password' => $this->cfg['imap']['pass'],
            'protocol' => 'imap',
        ];
        $client = $this->makeClient !== null
            ? ($this->makeClient)($settings)
            : (new \Webklex\PHPIMAP\ClientManager())->make($settings);
        $client->connect();
        try {
            // An idle run is connect, login, STATUS, logout. The folder is
            // built by name, as getFolders() builds each one it lists, rather
            // than looked up with getFolder(), which LISTs every folder or
            // label in the mailbox first; its delimiter falls back to the
            // configured one, and a name without one never needs it. STATUS
            // goes straight to the connection: Client::getConnection() sends
            // a NOOP each time it is asked. Only with unread mail is the
            // folder selected, by messages().
            $inbox = new \Webklex\PHPIMAP\Folder($client, self::FOLDER, '', []);
            $status = $client->connection->folderStatus(self::FOLDER)->validatedData();
            $unseen = isset($status['unseen']) ? (int) $status['unseen'] : null;
            if ($unseen === 0) {
                return;
            }
            $query = $inbox->messages()
                ->unseen()
                ->setFetchBody(false)
                ->leaveUnread()
                ->setSequence(IMAP::ST_UID);
            if (!isset($status['uidvalidity']) && !isset($status['UIDVALIDITY'])) {
                throw new \RuntimeException('IMAP did not report UIDVALIDITY for ' . self::FOLDER);
            }
            $uidValidity = (string) ($status['uidvalidity'] ?? $status['UIDVALIDITY']);
            $connection = $client->getConnection();
            // search() returns only integer UIDs. Query::get() is deliberately
            // not used: Webklex batch-fetches and parses every RFC822 header in
            // get(), even when setFetchBody(false) is set.
            $uids = self::unseenUids(
                $unseen,
                (int) ($status['messages'] ?? 0),
                static fn(): array => array_values($query->search()->toArray()),
                // A bare sequence set is a position criterion (RFC 3501 6.4.4):
                // "UID SEARCH UNSEEN 1:2000" lists the UIDs of the unread
                // among the first 2000 messages.
                static fn(string $set): array => (array) $connection->search(['UNSEEN', $set], IMAP::ST_UID)->validatedData(),
            );
            $mailbox = (string) ($this->cfg['imap']['host'] ?? '') . "\0"
                . (string) ($this->cfg['imap']['user'] ?? '') . "\0" . self::FOLDER . "\0" . $uidValidity;
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
                // LOGOUT directly: Client::disconnect() asks isConnected()
                // first, which is one more NOOP. Once logged out it is not
                // connected, so disconnect() (and the destructor) send nothing.
                $client->connection?->logout();
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Unread UIDs, oldest first. A normal unread count is one search, as it
     * always was. A large one, or none reported, is searched in windows of
     * message positions (1 to the message count, which are dense whatever
     * the UIDs are), from the first, and only as far as the caller keeps
     * reading: a run that stops after ten messages never lists the rest. A
     * mailbox is crossed in count / POSITION_WINDOW searches, so UIDs that
     * run into the billions cost nothing more; a run makes at most that
     * many, counted once at the start, and never more than SEARCHES_MAX. The
     * last window runs to the mailbox's end ("*"), so a message that came
     * in meanwhile is found and an expunge never names a position past it;
     * a UID no greater than one already listed is not listed again. Nothing
     * is remembered between runs, so a message marked unread again for a
     * retry is found the same way as any other. Pure apart from the two
     * searches; tested.
     *
     * @param callable():array $searchAll every unread UID
     * @param callable(string):array $searchPositions unread UIDs among positions "a:b"
     * @return \Generator<int,int>
     */
    public static function unseenUids(?int $unseenCount, int $messageCount, callable $searchAll, callable $searchPositions): \Generator
    {
        if (($unseenCount !== null && $unseenCount <= self::SEARCH_ALL_MAX) || $messageCount < 1) {
            $all = array_map('intval', $searchAll());
            sort($all, SORT_NUMERIC);
            yield from $all;
            return;
        }
        $needed = intdiv($messageCount + self::POSITION_WINDOW - 1, self::POSITION_WINDOW);
        $searches = min($needed, self::SEARCHES_MAX);
        $listed = 0;
        for ($i = 0; $i < $searches; $i++) {
            $low = $i * self::POSITION_WINDOW + 1;
            $high = $i === $needed - 1 ? '*' : (string) ($low + self::POSITION_WINDOW - 1);
            $found = array_map('intval', $searchPositions($low . ':' . $high));
            sort($found, SORT_NUMERIC);
            foreach ($found as $uid) {
                if ($uid > $listed) {
                    $listed = $uid;
                    yield $uid;
                }
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

    /** @return array{messageId:string,subject:string,from:string,appMail:?string} from headers alone */
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
            'appMail' => self::appMark($message),
        ];
    }

    /** The value of this app's own header (EmailSender::APP_HEADER), or null when the message has none. */
    private static function appMark(object $message): ?string
    {
        $header = method_exists($message, 'getHeader') ? $message->getHeader() : null;
        if (!is_object($header) || !method_exists($header, 'has') || !$header->has(EmailSender::APP_HEADER)) {
            return null;
        }
        $value = trim((string) $header->get(EmailSender::APP_HEADER));
        return $value !== '' ? mb_substr($value, 0, 40) : null;
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
