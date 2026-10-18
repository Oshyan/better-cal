<?php

declare(strict_types=1);

return new class implements \BetterCal\Plugin\PluginInterface {
    public function validateSettings(array $values): array
    {
        if (isset($GLOBALS['credentialValidationBarrier'])) {
            $barrier = $GLOBALS['credentialValidationBarrier'];
            unset($GLOBALS['credentialValidationBarrier']);
            $barrier();
        }
        return ($values['mode'] ?? '') === 'reject'
            ? ['credential' => (string) ($values['credential'] ?? '')] : [];
    }

    public function runJob(\BetterCal\Plugin\PluginHost $host, string $jobId): void
    {
        $host->log((string) ($host->settings()['credential'] ?? ''));
    }
};
