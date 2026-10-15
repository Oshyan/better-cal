<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\SettingsFile;

/**
 * Brings an install's private files up to the 0.9.83 layout
 * (docs/design-private-files.md, #142), wherever they came from: `.env`
 * becomes the guarded `.env.php`, here and in previous versions update.php
 * kept beside the app (Updater::convertibleCopies), and database backups
 * written before backups were encrypted get encrypted. Safe to run any
 * number of times; it only acts on what's still in the old form.
 *
 * Run by update.php --finish, the database update page, the worker (each
 * minute, when there's anything to do) and `php server/bin/settings.php
 * --convert`. Files owned by another account (a root-owned `.env`) are left
 * for that account to convert.
 */
final class PrivateFiles
{
    /**
     * Whether there's anything in the old form that this account can
     * convert, cheaply, for the worker to check each minute before doing
     * any work.
     *
     * @param array{app_root:string,session_secret?:string} $cfg
     */
    public static function pending(array $cfg): bool
    {
        $appRoot = $cfg['app_root'];
        $mine = static fn(string $f): bool => !function_exists('posix_geteuid') || @fileowner($f) === posix_geteuid();
        $plain = SettingsFile::plain($appRoot);
        if (is_file($plain) && !is_link($plain) && $mine($plain)) {
            return true;
        }
        $updater = new Updater($appRoot);
        if ((string) ($cfg['session_secret'] ?? '') !== '') {
            foreach (self::backupDirs($updater) as $dir) {
                foreach (glob($dir . '/*.sql.gz') ?: [] as $f) {
                    if ($mine($f)) {
                        return true;
                    }
                }
            }
        }
        foreach ($updater->convertibleCopies() as $dir) {
            $f = SettingsFile::plain($dir);
            if (is_file($f) && !is_link($f) && $mine($f)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert what can be converted. Returns what was done, one line each,
     * and what couldn't be (as "Couldn't …" lines).
     *
     * @param array{app_root:string,session_secret:string} $cfg
     * @return list<string>
     */
    public static function secure(array $cfg, bool $anyOwner = false): array
    {
        $appRoot = $cfg['app_root'];
        $updater = new Updater($appRoot);
        $done = [];
        foreach (array_merge([$appRoot], $updater->convertibleCopies()) as $dir) {
            try {
                $what = SettingsFile::convert($dir, $anyOwner);
                if ($what !== null) {
                    $done[] = ($dir === $appRoot ? '' : basename($dir) . ': ') . $what;
                }
            } catch (\Throwable $e) {
                $done[] = "Couldn't convert the settings in " . basename($dir) . ': ' . $e->getMessage();
            }
        }
        $secret = (string) ($cfg['session_secret'] ?? '');
        if ($secret === '') {
            return $done;
        }
        $key = BackupCipher::keyFrom($secret);
        $state = $updater->readState();
        $stateChanged = false;
        foreach (self::backupDirs($updater) as $dir) {
            foreach (glob($dir . '/*.sql.gz') ?: [] as $plain) {
                if (!$anyOwner && function_exists('posix_geteuid') && @fileowner($plain) !== posix_geteuid()) {
                    continue;
                }
                try {
                    $encrypted = DbBackup::encryptFile($plain, $key);
                } catch (\Throwable $e) {
                    $done[] = "Couldn't encrypt the backup " . basename($plain) . ': ' . $e->getMessage();
                    continue;
                }
                $done[] = 'Encrypted the backup ' . basename($plain) . ' (now ' . basename($encrypted) . ').';
                if (is_array($state) && ($state['backup'] ?? null) === $plain) {
                    $state['backup'] = $encrypted;
                    $stateChanged = true;
                }
            }
        }
        if ($stateChanged) {
            $updater->writeState($state);
        }
        return $done;
    }

    /** Where backups may be: beside the app, and inside it (.backups). @return list<string> */
    private static function backupDirs(Updater $updater): array
    {
        $dirs = [];
        foreach ([$updater->parent() . '/' . $updater->name() . '-backups', $updater->appRoot . '/.backups'] as $dir) {
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }
}
