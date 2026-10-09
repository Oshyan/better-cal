<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * One place-search provider behind the location dropdown. A provider owns
 * everything specific to its service: the requests it makes, the
 * workarounds its quirks need, and mapping its responses into candidate rows
 * (PlaceSearch::row). PlaceSearch owns what holds for any provider: the house
 * number must match, address order, far flags, the airport pin, the limit.
 */
interface PlaceProvider
{
    /**
     * Candidates in the provider's own relevance order, each built with
     * PlaceSearch::row. Null when the provider could not be reached (told
     * apart from "nothing matched", which is an empty list).
     *
     * @param ?string $language the person's preferred language as a primary
     *   subtag ('en', 'es'), or null; the provider maps it to what it has
     * @return ?list<array<string,mixed>>
     */
    public function candidates(string $q, ?float $biasLat, ?float $biasLng, int $limit, ?string $language): ?array;

    /**
     * How much distance may reorder this provider's results for a non-address
     * query (PlaceSearch::rank's cap), or null to keep the provider's order:
     * a provider that already ranks by proximity needs no correction.
     */
    public function distanceCap(): ?float;

    /**
     * Who to credit for the results just given (the dropdown shows them):
     * the service and its data, as its terms ask.
     *
     * @return list<array{label:string,url:string}>
     */
    public function credits(): array;
}
