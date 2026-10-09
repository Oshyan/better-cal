<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\Notifier;
use BetterCal\Support\Time;

/**
 * Read-only release awareness. This class can fetch release metadata and
 * notify; it never downloads application code or invokes an installer.
 */
final class Updates
{
    /**
     * GitHub lists releases newest-created first, and a release is always
     * created after the ones before it, so the newest app release sits near
     * the top. Pages of 25 are read newest first and the scan stops at the
     * first page holding an app release (the highest version on it wins); a
     * run of extension releases only costs another page. It used to read the
     * whole list, up to three pages of 100, and fail once the list outgrew
     * them, so every check would have stopped after 300 releases.
     */
    public const RELEASE_PAGE_SIZE = 25;
    public const MAX_RELEASE_PAGES = 4;
    public const RELEASES_URL = 'https://api.github.com/repos/Oshyan/better-cal/releases?per_page=' . self::RELEASE_PAGE_SIZE . '&page=1';
    public const RELEASE_BASE = 'https://github.com/Oshyan/better-cal/releases/download/';
    public const MANIFEST_NAME = 'bettercal-release.json';
    public const MAX_MANIFEST_BYTES = 4096;
    /**
     * A release asset's download URL on github.com always answers with a
     * redirect to GitHub's asset storage, so fetching the manifest takes two
     * requests. The client's budget counts every hop, so it must cover them:
     * with a budget of 1 every check failed from the first release that had a
     * manifest ("HTTP request budget exhausted (1)").
     */
    public const MAX_REDIRECTS = 2;
    public const REQUEST_BUDGET = self::MAX_REDIRECTS + 1;
    /** The worker job's health row (worker.php HEALTH_JOB_LABELS). */
    public const HEALTH_SUBJECT = 'job:update_check';
    public const HEALTH_LABEL = 'Application update checks';

    /** @param ?\Closure(string,array):array{status:int,body:string} $fetch */
    public function __construct(
        private readonly Db $db,
        private readonly array $cfg,
        private readonly ?\Closure $fetch = null,
    ) {
    }

    /** Fetch and persist the newest app release. Returns the stored row. */
    public function check(bool $notify = true): array
    {
        $startingState = $this->row();
        $startingRevision = (int) ($startingState['revision'] ?? 0);
        try {
            $candidates = [];
            for ($page = 1; $page <= self::MAX_RELEASE_PAGES && $candidates === []; $page++) {
                $releaseResponse = $this->get(self::releasePageUrl($page), [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2026-03-10',
                ]);
                if ($releaseResponse['status'] !== 200) {
                    throw new \RuntimeException('GitHub Releases returned HTTP ' . $releaseResponse['status']);
                }
                $releases = json_decode($releaseResponse['body'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($releases) || !array_is_list($releases)) {
                    throw new \RuntimeException('GitHub Releases response was not a list');
                }
                foreach ($releases as $candidate) {
                    $tag = is_array($candidate) ? (string) ($candidate['tag_name'] ?? '') : '';
                    if (preg_match('/^v(\d+\.\d+\.\d+)$/D', $tag) === 1
                        && empty($candidate['draft']) && empty($candidate['prerelease'])) {
                        $candidates[] = $candidate;
                    }
                }
                if (count($releases) < self::RELEASE_PAGE_SIZE) {
                    break; // the end of the list
                }
            }
            if ($candidates === [] && $page > self::MAX_RELEASE_PAGES) {
                throw new \RuntimeException('No published Better-Cal application release among the newest '
                    . (self::RELEASE_PAGE_SIZE * self::MAX_RELEASE_PAGES) . ' releases');
            }
            if ($candidates === []) {
                throw new \RuntimeException('No published Better-Cal application release was found');
            }
            usort($candidates, static fn(array $a, array $b): int => version_compare(
                substr((string) $b['tag_name'], 1),
                substr((string) $a['tag_name'], 1)
            ));
            $release = $candidates[0];

            $version = substr((string) $release['tag_name'], 1);
            $current = $this->currentVersion();
            $previous = $startingState;
            if (!empty($previous['latest_version']) && version_compare($version, (string) $previous['latest_version'], '<')) {
                throw new \RuntimeException('Release metadata moved backwards');
            }

            $manifestAssets = array_values(array_filter(is_array($release['assets'] ?? null) ? $release['assets'] : [],
                static fn($asset): bool => is_array($asset) && ($asset['name'] ?? null) === self::MANIFEST_NAME));
            // The first update-aware build can legitimately see an older
            // release that predates manifests and immutable publication. Do
            // not invent a secure floor for that legacy release.
            if (version_compare($version, $current, '<=')
                && (($release['immutable'] ?? false) !== true || count($manifestAssets) === 0)) {
                $state = [
                    'checked_at' => Time::nowDb(),
                    'latest_version' => $version,
                    'priority' => 'routine',
                    'minimum_secure_version' => $previous['minimum_secure_version'] ?? null,
                    'release_url' => $this->releaseUrl($release, $version),
                    'released_at' => $this->releasedAt($release),
                    'manifest_digest' => null,
                    'last_error' => null,
                ];
            } else {
                $state = $this->verifiedManifestState($release, $version, $previous);
            }
            $stored = $this->saveAcceptedState($state);
            if ($notify) $this->notifySecurityFloor($stored);
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $this->saveError($message, $startingRevision);
            throw new \RuntimeException('Update check failed: ' . $message, 0, $e);
        }
        // Any successful check ends a failing streak, not only the daily
        // job's: otherwise a failure stayed on the System page for up to a
        // day after the cause was fixed, while checks from Settings and the
        // banner were succeeding. Only a failing row is written (create:
        // false), and the health record never decides the check's result.
        try {
            (new SystemHealth($this->db))->recordOk(self::HEALTH_SUBJECT, 'job', null, self::HEALTH_LABEL, false);
        } catch (\Throwable) {
        }
        return $stored;
    }

    public function status(int $userId): array
    {
        $state = $this->row();
        $notice = $this->db->one('SELECT * FROM user_update_notices WHERE user_id = ?', [$userId]) ?? [];
        $settings = (new Settings($this->db))->forUser($userId);
        $preference = (string) ($settings['updateNotifications'] ?? 'all');
        $current = $this->currentVersion();
        $latest = is_string($state['latest_version'] ?? null) ? $state['latest_version'] : null;
        $floor = is_string($state['minimum_secure_version'] ?? null) ? $state['minimum_secure_version'] : null;
        $available = $latest !== null && version_compare($latest, $current, '>');
        $securityRequired = $floor !== null && version_compare($current, $floor, '<');
        $dismissed = $latest !== null && ($notice['dismissed_version'] ?? null) === $latest;
        $show = $preference !== 'off'
            && ($securityRequired || ($preference === 'all' && $available && !$dismissed));
        return [
            'currentVersion' => $current,
            'latestVersion' => $latest,
            'priority' => $state['priority'] ?? null,
            'minimumSecureVersion' => $floor,
            'releaseUrl' => $state['release_url'] ?? null,
            'releasedAt' => isset($state['released_at']) && $state['released_at'] !== null
                ? Time::dbToIso((string) $state['released_at']) : null,
            'checkedAt' => isset($state['checked_at']) && $state['checked_at'] !== null
                ? Time::dbToIso((string) $state['checked_at']) : null,
            'lastError' => $state['last_error'] ?? null,
            'preference' => $preference,
            'available' => $available,
            'securityRequired' => $securityRequired,
            'showBanner' => $show,
            'dismissible' => $show && !$securityRequired,
        ];
    }

    public function dismiss(int $userId): void
    {
        $status = $this->status($userId);
        if ($status['securityRequired']) {
            throw HttpError::badRequest('A required security update cannot be dismissed. Update Better-Cal or turn update notices off in Settings.');
        }
        if (!is_string($status['latestVersion']) || !$status['available']) return;
        $this->saveNotice($userId, ['dismissed_version' => $status['latestVersion']]);
    }

    private function verifiedManifestState(array $release, string $version, array $previous): array
    {
        if (($release['immutable'] ?? false) !== true) {
            throw new \RuntimeException('The newest release is not immutable');
        }
        $url = $this->releaseUrl($release, $version);
        $assets = array_values(array_filter(is_array($release['assets'] ?? null) ? $release['assets'] : [],
            static fn($asset): bool => is_array($asset) && ($asset['name'] ?? null) === self::MANIFEST_NAME));
        if (count($assets) !== 1) {
            throw new \RuntimeException('The release must contain exactly one ' . self::MANIFEST_NAME . ' asset');
        }
        $asset = $assets[0];
        $tag = 'v' . $version;
        $expectedUrl = self::RELEASE_BASE . rawurlencode($tag) . '/' . self::MANIFEST_NAME;
        if (($asset['browser_download_url'] ?? null) !== $expectedUrl) {
            throw new \RuntimeException('The release manifest download URL is not canonical');
        }
        $digest = (string) ($asset['digest'] ?? '');
        if (preg_match('/^sha256:([0-9a-f]{64})$/D', $digest, $m) !== 1) {
            throw new \RuntimeException('The release manifest has no valid GitHub SHA-256 digest');
        }
        $manifestResponse = $this->get($expectedUrl, ['Accept: application/octet-stream']);
        if ($manifestResponse['status'] !== 200) {
            throw new \RuntimeException('Release manifest returned HTTP ' . $manifestResponse['status']);
        }
        $raw = $manifestResponse['body'];
        if ($raw === '' || strlen($raw) > self::MAX_MANIFEST_BYTES || !hash_equals($m[1], hash('sha256', $raw))) {
            throw new \RuntimeException('Release manifest size or digest is invalid');
        }
        $manifest = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_keys($manifest) !== ['schemaVersion', 'version', 'priority', 'minimumSecureVersion']) {
            throw new \RuntimeException('Release manifest schema is invalid');
        }
        if ($manifest['schemaVersion'] !== 1 || $manifest['version'] !== $version
            || !in_array($manifest['priority'], ['routine', 'recommended', 'security'], true)
            || !is_string($manifest['minimumSecureVersion'])
            || preg_match('/^\d+\.\d+\.\d+$/D', $manifest['minimumSecureVersion']) !== 1
            || version_compare($manifest['minimumSecureVersion'], $version, '>')
            || ($manifest['priority'] === 'security' && $manifest['minimumSecureVersion'] !== $version)) {
            throw new \RuntimeException('Release manifest values are invalid');
        }
        $canonical = self::manifestJson($version, $manifest['priority'], $manifest['minimumSecureVersion']);
        if (!hash_equals($canonical, $raw)) {
            throw new \RuntimeException('Release manifest is not canonical');
        }
        if (!empty($previous['minimum_secure_version'])
            && version_compare($manifest['minimumSecureVersion'], (string) $previous['minimum_secure_version'], '<')) {
            throw new \RuntimeException('The cumulative minimum secure version moved backwards');
        }
        return [
            'checked_at' => Time::nowDb(),
            'latest_version' => $version,
            'priority' => $manifest['priority'],
            'minimum_secure_version' => $manifest['minimumSecureVersion'],
            'release_url' => $url,
            'released_at' => $this->releasedAt($release),
            'manifest_digest' => $m[1],
            'last_error' => null,
        ];
    }

    public static function manifestJson(string $version, string $priority, string $minimumSecureVersion): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'version' => $version,
            'priority' => $priority,
            'minimumSecureVersion' => $minimumSecureVersion,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function releaseUrl(array $release, string $version): string
    {
        $expected = 'https://github.com/Oshyan/better-cal/releases/tag/v' . $version;
        if (($release['html_url'] ?? null) !== $expected) {
            throw new \RuntimeException('Release URL is not canonical');
        }
        return $expected;
    }

    private function releasedAt(array $release): ?string
    {
        $raw = $release['published_at'] ?? null;
        if (!is_string($raw) || $raw === '') return null;
        try { return Time::toDb(Time::parseIso($raw)); }
        catch (\Throwable) { throw new \RuntimeException('Release publication time is invalid'); }
    }

    /** @return array{status:int,body:string} */
    private function get(string $url, array $headers): array
    {
        if ($this->fetch !== null) return ($this->fetch)($url, $headers);
        $manifestRequest = str_ends_with($url, '/' . self::MANIFEST_NAME);
        $http = new HttpClient(
            requestBudget: self::REQUEST_BUDGET,
            userAgent: HttpClient::userAgentFor('release checks'),
            maxBytes: $manifestRequest ? self::MAX_MANIFEST_BYTES : 1024 * 1024,
            maxRedirects: self::MAX_REDIRECTS,
            connectTimeoutMs: 5000,
            totalTimeoutMs: 15000,
            allowedSchemes: ['https'],
            maxTotalBytes: $manifestRequest ? self::MAX_MANIFEST_BYTES : 1024 * 1024,
        );
        return $http->get($url, $headers);
    }

    private static function releasePageUrl(int $page): string
    {
        return 'https://api.github.com/repos/Oshyan/better-cal/releases?per_page=' . self::RELEASE_PAGE_SIZE . '&page=' . $page;
    }

    private function currentVersion(): string
    {
        $version = (string) ($this->cfg['version'] ?? '0.0.0');
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new \RuntimeException('Installed Better-Cal version is invalid');
        }
        return $version;
    }

    private function row(): array
    {
        return $this->db->one('SELECT * FROM app_update_state WHERE id = 1') ?? ['id' => 1];
    }

    /** Persist only monotonic release state under the database row lock. */
    private function saveAcceptedState(array $state): array
    {
        return $this->db->tx(function () use ($state): array {
            $lock = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $current = $this->db->one('SELECT * FROM app_update_state WHERE id = 1' . $lock) ?? ['id' => 1, 'revision' => 0];
            $incomingVersion = (string) ($state['latest_version'] ?? '');
            $storedVersion = (string) ($current['latest_version'] ?? '');
            if ($storedVersion !== '' && version_compare($incomingVersion, $storedVersion, '<')) {
                return $current; // A slower, older check lost a race to newer verified state.
            }
            if ($incomingVersion === $storedVersion && !empty($current['manifest_digest']) && empty($state['manifest_digest'])) {
                return $current; // A legacy response cannot erase a verified same-version manifest.
            }
            $incomingFloor = (string) ($state['minimum_secure_version'] ?? '');
            $storedFloor = (string) ($current['minimum_secure_version'] ?? '');
            if ($storedFloor !== '' && ($incomingFloor === '' || version_compare($incomingFloor, $storedFloor, '<'))) {
                throw new \RuntimeException('The cumulative minimum secure version moved backwards while saving');
            }
            if ($incomingVersion === $storedVersion && !empty($current['manifest_digest'])
                && !empty($state['manifest_digest'])
                && !hash_equals((string) $current['manifest_digest'], (string) $state['manifest_digest'])) {
                throw new \RuntimeException('Immutable release metadata changed for an accepted version');
            }
            $state['revision'] = (int) ($current['revision'] ?? 0) + 1;
            $this->db->update('app_update_state', $state, 'id = 1', []);
            return $this->row();
        });
    }

    /** Do not let an older failed request overwrite a newer successful check. */
    private function saveError(string $message, int $startingRevision): void
    {
        $this->db->update('app_update_state', [
            'checked_at' => Time::nowDb(),
            'last_error' => $message,
            'revision' => $startingRevision + 1,
        ], 'id = 1 AND revision = ?', [$startingRevision]);
    }

    private function saveNotice(int $userId, array $changes): void
    {
        if ($this->db->scalar('SELECT user_id FROM user_update_notices WHERE user_id = ?', [$userId]) === null) {
            $this->db->insert('user_update_notices', ['user_id' => $userId] + $changes);
        } else {
            $this->db->update('user_update_notices', $changes, 'user_id = ?', [$userId]);
        }
    }

    private function notifySecurityFloor(array $state): void
    {
        $floor = (string) ($state['minimum_secure_version'] ?? '');
        if ($floor === '' || version_compare($this->currentVersion(), $floor, '>=')) return;
        foreach ($this->db->all('SELECT id, settings_json FROM users') as $user) {
            $stored = is_string($user['settings_json'] ?? null) ? json_decode((string) $user['settings_json'], true) : null;
            $settings = Settings::withDefaults(is_array($stored) ? $stored : []);
            if (($settings['updateNotifications'] ?? 'all') === 'off') continue;
            $userId = (int) $user['id'];
            $notice = $this->db->one('SELECT notified_secure_floor FROM user_update_notices WHERE user_id = ?', [$userId]);
            if (($notice['notified_secure_floor'] ?? null) === $floor) continue;
            $sent = (new Notifier($this->db, $this->cfg))->send(
                $userId,
                'Better-Cal security update available',
                'Update to version ' . $floor . ' or newer. Open Better-Cal for the release link and instructions.',
                '/settings/about',
                'better-cal-security-update-' . $floor,
            );
            if ($sent['push'] > 0 || $sent['email']) {
                $this->saveNotice($userId, ['notified_secure_floor' => $floor]);
            }
        }
    }
}
