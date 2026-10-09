<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Transport for the keyed place-search services (LocationIQ, Stadia): the
 * provider builds complete request URLs, key included, and gets the decoded
 * JSON back. Production checks each URL belongs to that provider's API host.
 */
interface KeyedGeocoderTransport
{
    /**
     * Requests in parallel, one result per URL: the decoded JSON, [] when the
     * service answered "nothing found", or null on failure.
     *
     * @param 'locationiq'|'stadia' $provider
     * @param list<string> $urls
     * @return list<?array>
     */
    public function keyed(string $provider, array $urls): array;
}
