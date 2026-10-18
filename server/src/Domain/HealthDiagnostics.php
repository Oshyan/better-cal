<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/** Token-safe diagnostics are assembled from recognized facts, never raw errors. */
final class HealthDiagnostics
{
    public static function error(?string $error): ?string
    {
        if ($error === null || $error === '') return null;
        $reason = 'Operation failed';
        if (preg_match('/\bHTTP[ :]+([45][0-9]{2})\b/i', $error, $m)) {
            $reason = 'HTTP ' . $m[1];
        } elseif (preg_match('/\b(timed out|timeout|deadline)\b/i', $error)) {
            $reason = 'Request timed out';
        } elseif (preg_match('/\b(resolve|resolution|DNS)\b/i', $error)) {
            $reason = 'Host lookup failed';
        } elseif (preg_match('/\b(certificate|TLS|SSL)\b/i', $error)) {
            $reason = 'Secure connection failed';
        }
        if (preg_match('~https?://[^\s<>"\x27]+~i', $error, $url)) {
            $host = parse_url($url[0], PHP_URL_HOST);
            if (is_string($host) && strlen($host) <= 253 && preg_match('/^[a-z0-9.-]+$/iD', $host)) {
                $reason .= ' at ' . $host;
            }
        }
        return $reason . '. Sign in with a browser for full details.';
    }

    public static function forToken(array $health): array
    {
        unset($health['cron']['command'], $health['cron']['line'], $health['emailAlerts']['systemTo'], $health['emailAlerts']['accountTo']);
        foreach ($health['rows'] as &$row) {
            unset($row['endpointHash']);
            $row['lastError'] = self::error($row['lastError'] ?? null);
            if (($row['kind'] ?? '') === 'push') {
                $row['label'] = 'Push delivery';
                $row['subject'] = 'push';
            }
        }
        unset($row);
        return $health;
    }
}
