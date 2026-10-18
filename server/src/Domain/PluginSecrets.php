<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Secrets;

/** One representation for secret storage, runtime reads and public set/unset state. */
final class PluginSecrets
{
    public const PRIVATE_DIAGNOSTICS = '__bettercalPrivateDiagnostics';
    public const HIDDEN_DIAGNOSTIC = 'Details hidden because this plugin uses secret settings.';
    private const SEALED = '__bettercalSecret';

    public static function hasSecrets(array $manifest): bool
    {
        foreach (['settings', 'calendarSettings', 'eventSettings'] as $scope) {
            foreach ($manifest[$scope] ?? [] as $field) {
                if (($field['type'] ?? '') === 'secret') return true;
            }
        }
        return false;
    }

    public static function schema(array $schema): array
    {
        foreach ($schema as &$field) {
            if (($field['type'] ?? '') === 'secret') unset($field['default']);
        }
        unset($field);
        return array_values($schema);
    }

    /** Redact envelopes even if a plugin is removed or its schema changes. */
    public static function publicStored(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_key_exists(self::SEALED, $value)) return ['set' => true];
        unset($value[self::PRIVATE_DIAGNOSTICS]);
        return array_map(self::publicStored(...), $value);
    }

    public static function project(array $schema, array $values): array
    {
        foreach ($schema as $field) {
            if (($field['type'] ?? '') === 'secret') {
                $key = (string) $field['key'];
                $values[$key] = ['set' => isset($values[$key]) && $values[$key] !== ''];
            }
        }
        return self::publicStored($values);
    }

    public static function seal(string $pluginId, string $scope, array $schema, array $values, string $secret): array
    {
        foreach ($schema as $field) {
            $key = (string) $field['key'];
            if (($field['type'] ?? '') !== 'secret' || !isset($values[$key])) continue;
            if (is_array($values[$key]) && isset($values[$key][self::SEALED])) continue;
            if (!is_string($values[$key]) || $values[$key] === '') {
                throw HttpError::badRequest('A secret must be nonempty text, or null to clear it.');
            }
            if ($secret === '') {
                throw HttpError::conflict('session_secret_missing', 'Saving a plugin secret needs BETTERCAL_SESSION_SECRET. Open Settings, System for the settings line to add.');
            }
            $values[$key] = [self::SEALED => Secrets::sealFor(self::purpose($pluginId, $scope, $key), $values[$key], $secret)];
        }
        return $values;
    }

    public static function open(string $pluginId, string $scope, array $values, string $secret): array
    {
        unset($values[self::PRIVATE_DIAGNOSTICS]);
        foreach ($values as $key => &$value) {
            if (is_array($value) && isset($value[self::SEALED])) {
                $value = Secrets::openFor(self::purpose($pluginId, $scope, (string) $key), (string) $value[self::SEALED], $secret);
            }
        }
        unset($value);
        return $values;
    }

    private static function purpose(string $id, string $scope, string $key): string
    {
        return 'plugin-' . substr(hash('sha256', $id . "\0" . $scope . "\0" . $key), 0, 56);
    }
}
