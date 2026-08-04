<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * IMAP transport for the mail ingest worker: fetches unseen messages from
 * the calendar@ mailbox and decomposes each into the shape MailIngest
 * expects, marking them seen afterwards. webklex/php-imap runs its own
 * protocol client, so no ext-imap is needed. All webklex usage stays inside
 * this class; the domain logic is testable without it.
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
     * @return list<array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string}>
     */
    public function fetchUnseen(int $limit = 10): array
    {
        if (!$this->isConfigured() || !class_exists(\Webklex\PHPIMAP\ClientManager::class)) {
            return [];
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
        $inbox = $client->getFolder('INBOX');
        $messages = $inbox->messages()->unseen()->limit($limit)->get();

        $out = [];
        foreach ($messages as $message) {
            try {
                $out[] = $this->decompose($message);
                $message->setFlag('Seen');
            } catch (\Throwable $e) {
                // A malformed message must not wedge the queue: mark it seen
                // and move on; the error is visible in the worker log.
                error_log('mail ingest decompose failed: ' . $e->getMessage());
                try {
                    $message->setFlag('Seen');
                } catch (\Throwable) {
                }
            }
        }
        $client->disconnect();
        return $out;
    }

    /** @return array{messageId:string,subject:string,from:string,icsParts:list<string>,html:?string,text:?string} */
    private function decompose(object $message): array
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

        $icsParts = [];
        foreach ($message->getAttachments() as $attachment) {
            $name = strtolower((string) $attachment->getName());
            $mime = strtolower((string) $attachment->getMimeType());
            if (str_ends_with($name, '.ics') || str_contains($mime, 'text/calendar')) {
                $icsParts[] = (string) $attachment->getContent();
            }
        }
        // Inline text/calendar bodies (Google sends invites this way too).
        $raw = (string) $message->getRawBody();
        if ($icsParts === [] && stripos($raw, 'BEGIN:VCALENDAR') !== false) {
            if (preg_match('/BEGIN:VCALENDAR.*?END:VCALENDAR/s', quoted_printable_decode($raw), $m) === 1) {
                $icsParts[] = $m[0];
            }
        }

        $html = $message->hasHTMLBody() ? (string) $message->getHTMLBody() : null;
        $text = $message->hasTextBody() ? (string) $message->getTextBody() : null;
        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $html) ?? '')));
        }

        return [
            'messageId' => $messageId,
            'subject' => (string) $message->getSubject(),
            'from' => $from,
            'icsParts' => $icsParts,
            'html' => $html,
            'text' => $text,
        ];
    }
}
