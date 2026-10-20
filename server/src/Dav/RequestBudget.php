<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Support\Limits;

/**
 * Bound a CalDAV request body before Sabre sees it (review of 0.9.87).
 *
 * Sabre buffers a whole PUT, honours X-Expected-Entity-Length, and parses
 * REPORT, PROPPATCH and MKCALENDAR XML of any size. Real clients send one
 * event or a few KB of XML, so a PUT may carry one object plus room for
 * headers and folding, and everything else 1 MiB. The body is copied through
 * a capped read, so a missing or false Content-Length (chunked uploads)
 * cannot get past the limit either.
 */
final class RequestBudget
{
    public const OTHER_BYTES = 1048576;
    private const PUT_SLACK = 65536;

    public static function limit(string $method): int
    {
        return strtoupper($method) === 'PUT' ? Limits::get('DAV_OBJECT_BYTES') + self::PUT_SLACK : self::OTHER_BYTES;
    }

    /**
     * The request body as a rewound temp stream, or null when it is over the
     * limit. Drops X-Expected-Entity-Length from $server either way.
     *
     * @param resource $input
     * @return resource|null
     */
    public static function body(array &$server, $input)
    {
        unset($server['HTTP_X_EXPECTED_ENTITY_LENGTH']);
        $limit = self::limit((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $declared = $server['CONTENT_LENGTH'] ?? $server['HTTP_CONTENT_LENGTH'] ?? null;
        if ($declared !== null && $declared !== '' && (!ctype_digit((string) $declared) || (float) $declared > $limit)) {
            return null;
        }
        $body = fopen('php://temp', 'w+b');
        $copied = stream_copy_to_stream($input, $body, $limit + 1);
        if ($copied === false || $copied > $limit) {
            fclose($body);
            return null;
        }
        rewind($body);
        return $body;
    }
}
