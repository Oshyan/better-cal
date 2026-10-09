<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/** Provider transport seam: production is policied HTTP; tests can stay local. */
interface GeocoderTransport
{
    /**
     * Photon requests in parallel, one result per parameter set (null on
     * failure). A set carrying '_endpoint' => 'reverse' goes to /reverse
     * instead of the search endpoint.
     *
     * @param list<array<string,mixed>> $paramSets @return list<?array>
     */
    public function photon(array $paramSets): array;

    /** @param array<string,mixed> $params */
    public function openMeteo(array $params): ?array;
}
