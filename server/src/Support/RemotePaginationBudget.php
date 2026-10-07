<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * One aggregate safety boundary for a remote paginated operation.
 *
 * The provider controls the page bodies and continuation tokens, so request
 * count alone is not enough: a broken or hostile sequence must not reset its
 * item, byte, time, or progress budget on every page. Sequence-local token
 * history can be reset for a legitimate provider restart (Google HTTP 410),
 * while the aggregate counters deliberately remain spent.
 */
final class RemotePaginationBudget
{
    private int $pages = 0;
    private int $items = 0;
    private int $bytes = 0;

    /** @var list<string> */
    private array $seenTokens = [];

    public function __construct(
        private readonly string $label,
        private readonly int $maxPages,
        private readonly int $maxItems,
        private readonly int $maxBytes,
        private readonly float $deadline,
        private readonly int $maxTokenBytes = 8192,
        private readonly ?\Closure $clock = null,
    ) {
        if ($maxPages < 1 || $maxItems < 1 || $maxBytes < 1 || $maxTokenBytes < 1) {
            throw new \InvalidArgumentException('Pagination budgets must be positive');
        }
    }

    /**
     * Admit one request and return the largest safe provider page size.
     * The page is charged before the network call so failed responses and a
     * stale-token retry cannot reset the request/page budget.
     */
    public function beginPage(int $preferredPageSize): int
    {
        $this->assertWithinDeadline();
        if ($this->pages >= $this->maxPages) {
            throw new \RuntimeException($this->label . ' exceeded its ' . number_format($this->maxPages) . '-page safety limit; the existing data was kept unchanged.');
        }
        $remaining = $this->maxItems - $this->items;
        if ($remaining < 1) {
            throw new \RuntimeException($this->itemLimitMessage());
        }
        $this->pages++;
        return min(max(1, $preferredPageSize), $remaining);
    }

    /** Charge the decompressed response body before decoding or retaining it. */
    public function consumeResponse(string $body): void
    {
        $next = $this->bytes + strlen($body);
        if ($next > $this->maxBytes) {
            throw new \RuntimeException(
                $this->label . ' exceeded its ' . $this->formatBytes($this->maxBytes)
                . ' cumulative response limit; the existing data was kept unchanged.'
            );
        }
        $this->bytes = $next;
        $this->assertWithinDeadline();
    }

    /**
     * Validate and charge a page's raw provider items before mapping them.
     *
     * @return list<array<string,mixed>>
     */
    public function acceptItems(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException($this->label . ' returned a malformed item list; the existing data was kept unchanged.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException($this->label . ' returned a malformed item; the existing data was kept unchanged.');
            }
        }
        if (count($items) > $this->maxItems - $this->items) {
            throw new \RuntimeException($this->itemLimitMessage());
        }
        $this->items += count($items);
        $this->assertWithinDeadline();
        return $items;
    }

    /**
     * Validate forward progress. Missing/null means the sequence is complete;
     * every other provider token must be a bounded, non-empty opaque string.
     */
    public function nextPageToken(array $data): ?string
    {
        if (!array_key_exists('nextPageToken', $data) || $data['nextPageToken'] === null) {
            return null;
        }
        $token = $data['nextPageToken'];
        if (!is_string($token)) {
            throw new \RuntimeException($this->label . ' returned an invalid page token; the existing data was kept unchanged.');
        }
        if (trim($token) === '') {
            throw new \RuntimeException($this->label . ' returned an invalid empty page token; the existing data was kept unchanged.');
        }
        if (strlen($token) > $this->maxTokenBytes) {
            throw new \RuntimeException($this->label . ' returned an oversized page token; the existing data was kept unchanged.');
        }
        if (in_array($token, $this->seenTokens, true)) {
            throw new \RuntimeException($this->label . ' repeated a page token instead of making progress; the existing data was kept unchanged.');
        }
        if ($this->items >= $this->maxItems) {
            throw new \RuntimeException($this->itemLimitMessage());
        }
        $this->seenTokens[] = $token;
        $this->assertWithinDeadline();
        return $token;
    }

    /** A provider-declared restart gets fresh token history, not fresh work. */
    public function restartSequence(): void
    {
        $this->seenTokens = [];
        $this->assertWithinDeadline();
    }

    public function assertWithinDeadline(): void
    {
        if ($this->now() >= $this->deadline) {
            throw new \RuntimeException($this->label . ' exceeded its elapsed-time safety limit; the existing data was kept unchanged.');
        }
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function pagesUsed(): int
    {
        return $this->pages;
    }

    public function itemsUsed(): int
    {
        return $this->items;
    }

    public function bytesUsed(): int
    {
        return $this->bytes;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function itemLimitMessage(): string
    {
        return $this->label . ' exceeded its ' . number_format($this->maxItems)
            . '-item safety limit; the existing data was kept unchanged.';
    }

    private function formatBytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MiB';
    }
}
