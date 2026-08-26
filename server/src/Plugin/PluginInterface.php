<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

/**
 * The contract a plugin's Plugin.php implements. The file is require()d and
 * must RETURN an instance (typically `return new class implements
 * PluginInterface { ... };`) — no autoloading, no naming coordination.
 *
 * Plugin code executes only here: inside worker jobs (runJob) and inside the
 * short synchronous validation hook. Nothing a plugin does runs on a page
 * request; request handlers read the tables the host wrote.
 */
interface PluginInterface
{
    /** One scheduled unit of work. Everything reaches the app through $host. */
    public function runJob(PluginHost $host, string $jobId): void;

    /**
     * Validate settings values (plugin scope) beyond the schema's own type
     * checks. Return a map of key => error message; empty array accepts.
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public function validateSettings(array $values): array;
}
