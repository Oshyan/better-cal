<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\Limits;

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
 * - Headers first. The list is fetched WITHOUT bodies, and the server is asked
 *   for each message's size. One over Limits::MAIL_BYTES is never downloaded:
 *   it is marked seen and reported as skipped.
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
     * @param ?callable(array{messageId:string,subject:string,from:string}):bool $begin
     *        called before a body is fetched; return false to skip the message
     *        (already handled, or started before and never finished)
     * @param ?callable(string):void $abort called with the messageId when fetching failed in a retryable way
     * @return \Generator<int, array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string,oversize?:int}>
     */
    public function fetchUnseen(int $limit = 10, ?callable $begin = null, ?callable $abort = null): \Generator
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
            $messages = $inbox->messages()->unseen()->setFetchBody(false)->leaveUnread()->limit($limit)->get();
            foreach ($messages as $message) {
                $envelope = null;
                try {
                    $envelope = self::envelope($message);
                    $size = (int) $message->getSize();
                    if ($size > Limits::get('MAIL_BYTES')) {
                        $message->setFlag('Seen');
                        yield $envelope + ['icsParts' => [], 'html' => null, 'text' => null, 'oversize' => $size];
                        continue;
                    }
                    if ($begin !== null && $begin($envelope) === false) {
                        $message->setFlag('Seen');
                        continue;
                    }
                    // Seen BEFORE the body: see "No poison messages" above.
                    $message->setFlag('Seen');
                    $message->parseBody();
                    $decomposed = $this->decompose($message, $envelope);
                } catch (\Throwable $e) {
                    // Caught means survivable (a dropped connection, a malformed
                    // part): put it back so the next run tries again.
                    error_log('mail ingest fetch failed: ' . $e->getMessage());
                    try {
                        $message->unsetFlag('Seen');
                    } catch (\Throwable) {
                    }
                    if ($abort !== null && $envelope !== null) {
                        $abort($envelope['messageId']);
                    }
                    continue;
                }
                yield $decomposed;
                unset($decomposed);
            }
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /** @return array{messageId:string,subject:string,from:string} from headers alone */
    private static function envelope(object $message): array
    {
        $messageId = trim((string) $message->getMessageId(), " \t<>");
        if ($messageId === '') {
            $messageId = 'no-id-' . sha1((string) $message->getSubject() . (string) $message->getDate());
        }
        $fromAttr = $message->getFrom();
        $from = '';
        if ($fromAttr && isset($fromAttr[0])) {
            $from = strtolower((string) ($fromAttr[0]->mail ?? ''));
        }
        return ['messageId' => $messageId, 'subject' => (string) $message->getSubject(), 'from' => $from];
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
            if (preg_match('/BEGIN:VCALENDAR.*?END:VCALENDAR/s', quoted_printable_decode($raw), $m) === 1 && strlen($m[0]) <= $maxIcs) {
                $icsParts[] = $m[0];
            }
        }
        unset($raw);

        $html = $message->hasHTMLBody() ? self::clip((string) $message->getHTMLBody()) : null;
        $text = $message->hasTextBody() ? self::clip((string) $message->getTextBody()) : null;
        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $html) ?? '')));
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
