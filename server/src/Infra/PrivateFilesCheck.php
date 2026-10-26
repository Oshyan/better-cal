<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\SettingsFile;

/**
 * Whether the install's private files can be downloaded, in plain words
 * (docs/private-files.md, #142). Shown on Settings, System as
 * "Private files"; setup and the updaters show it only when it isn't Safe.
 *
 * Some hosts can only put a subdomain inside the main site's public_html, so
 * the whole Better-Cal folder may be reachable from the main domain. The
 * check asks that domain for a probe file it writes into the folder: a PHP
 * file whose first line is the settings file's guard, then a random token.
 *
 * - The token comes back: the server hands out PHP files as text there, so
 *   the settings file would be readable. Exposed.
 * - An empty page: the server runs PHP there, so the settings file shows as
 *   a blank page. Safe, reachable but guarded.
 * - 403, while a made-up address on the same domain gives 404: the folder is
 *   in the web space and refused (the .htaccess). Safe.
 * - 404 everywhere: the domain doesn't serve the folder at the place it
 *   would. Probably safe; it can't prove no other address does. That's
 *   also the best it can say for a folder outside the usual web folders
 *   (public_html and the like): a web root can be named anything.
 * - Nothing answers: outgoing requests are blocked. Not checked.
 *
 * Not on a timer: at the end of setup and of each updater, and from "Check
 * now". The last result is kept in .runtime-cache, guarded like the settings.
 */
final class PrivateFilesCheck
{
    /** What an exposed settings file means for the secrets in it. */
    private const CHANGE_SECRETS = "Once it's fixed, change the passwords and keys in it, since they may already have been copied: "
        . 'the database password first, then any mail, Google or other service passwords and keys it holds. The install guide, under Keeping private files private, says what changing the session secret involves.';

    public const COOLDOWN_SECONDS = 60;
    public const DOCS = 'https://github.com/Oshyan/better-cal/blob/main/docs/install.md#keeping-private-files-private';
    /** Folder names hosts use for a site's public files. */
    private const DOCROOTS = ['public_html', 'htdocs', 'httpdocs', 'www', 'wwwroot', 'html', 'web', 'public'];
    /** Second-level suffixes under which a name is still a public suffix (example.co.uk). */
    private const TWO_LEVEL = ['co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'co.jp', 'ne.jp', 'or.jp', 'co.za', 'org.za', 'com.br', 'net.br', 'org.br', 'co.in', 'net.in', 'org.in',
        'com.mx', 'org.mx', 'com.ar', 'com.tr', 'com.cn', 'net.cn', 'org.cn', 'com.tw', 'com.sg', 'com.hk', 'co.kr', 'or.kr', 'co.il', 'org.il',
        'com.my', 'com.ph', 'com.vn', 'co.id', 'co.th', 'in.th', 'com.pl', 'com.es', 'com.pt', 'co.at', 'or.at', 'com.ua', 'com.ru', 'com.co',
        'com.pe', 'com.ec', 'com.uy', 'com.ng', 'co.ke'];
    /**
     * Names under which other people get subdomains (dynamic DNS, tunnels,
     * hosting platforms). An install at one has no main domain of its own,
     * so nothing is asked of the shared name.
     */
    private const SHARED = ['ts.net', 'duckdns.org', 'ddns.net', 'no-ip.org', 'no-ip.biz', 'hopto.org', 'zapto.org', 'sytes.net',
        'servebeer.com', 'myftp.org', 'dynu.net', 'freeddns.org', 'dyndns.org', 'dynv6.net', 'mooo.com', 'afraid.org', 'synology.me',
        'myqnapcloud.com', 'tplinkdns.com', 'asuscomm.com', 'github.io', 'gitlab.io', 'netlify.app', 'vercel.app', 'pages.dev',
        'workers.dev', 'herokuapp.com', 'onrender.com', 'fly.dev', 'railway.app', 'web.app', 'firebaseapp.com', 'appspot.com',
        'azurewebsites.net', 'cloudfront.net', 'ngrok.io', 'ngrok-free.app', 'ngrok.app', 'trycloudflare.com', 'loca.lt', 'nip.io',
        'sslip.io', 'xip.io', 'local', 'localhost', 'internal', 'lan', 'home.arpa', 'invalid'];

    public static function resultPath(string $appRoot): string
    {
        return $appRoot . '/.runtime-cache/private-files.php';
    }

    /** The last result, or null when it has never run. @return ?array<string,mixed> */
    public static function last(string $appRoot): ?array
    {
        $raw = @file_get_contents(self::resultPath($appRoot));
        $nl = $raw === false ? false : strpos($raw, "\n");
        $r = $nl === false ? null : json_decode(substr((string) $raw, $nl + 1), true);
        return is_array($r) ? $r : null;
    }

    /** Seconds until "Check now" may run again (0 when it may). */
    public static function wait(string $appRoot): int
    {
        $last = self::last($appRoot);
        $at = is_array($last) ? strtotime((string) ($last['checkedAt'] ?? '')) : false;
        return $at === false ? 0 : max(0, self::COOLDOWN_SECONDS - (time() - $at));
    }

    /**
     * Run the check, keep the result, return it.
     *
     * @param array{app_root:string,base_url:string} $cfg
     * @param ?callable(list<string>):list<array{status:int,body:string,location:?string}> $fetch
     * @return array{status:string,title:string,message:string,notes:list<string>,checkedAt:string}
     */
    public static function run(array $cfg, ?callable $fetch = null): array
    {
        $result = self::evaluate($cfg, $fetch ?? self::fetcher());
        $result['checkedAt'] = date(DATE_ATOM);
        $dir = dirname(self::resultPath($cfg['app_root']));
        if (!is_dir($dir) && @mkdir($dir, 0700, true) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($dir, (int) fileowner($cfg['app_root']));
            @chgrp($dir, (int) filegroup($cfg['app_root']));
        }
        if (is_dir($dir)) {
            SettingsFile::writeAtomic(self::resultPath($cfg['app_root']), SettingsFile::GUARD_PREFIX . " // Better-Cal private-files check\n" . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n", 0600);
        }
        return $result;
    }

    /**
     * @param callable(list<string>):list<array{status:int,body:string,location:?string}> $fetch
     * @return array{status:string,title:string,message:string,notes:list<string>}
     */
    public static function evaluate(array $cfg, callable $fetch): array
    {
        $appRoot = rtrim($cfg['app_root'], '/');
        $encrypted = (string) ($cfg['session_secret'] ?? '') !== '';
        $andBackups = $encrypted ? ' and your backups are encrypted' : '';
        $folder = basename($appRoot);
        $baseHost = strtolower((string) parse_url((string) $cfg['base_url'], PHP_URL_HOST));
        $place = self::candidatePath($appRoot);
        $plainSettings = is_file(SettingsFile::plain($appRoot));

        // No folder in the path that hosts usually serve (public_html and
        // the like). A web root can be named anything, so that isn't proof:
        // ask the main domain for the folder at its top level anyway, and
        // never call the result more than "Probably safe".
        $guessed = $place === null;
        $place ??= ['path' => '/' . $folder . '/', 'docroot' => null, 'domain' => null];
        $notFound = $guessed ? 'unlikely' : 'probably';
        $settingsFiles = array_values(array_filter(['.env', '.env.php'], static fn(string $f): bool => is_file($appRoot . '/' . $f))) ?: ['.env.php'];
        $hosts = self::candidateHosts($baseHost, $place['domain']);
        $shown = ($hosts[0] ?? $baseHost) . $place['path'];
        if ($hosts === []) {
            return self::finish('probably', 'Probably safe.', self::probablyText($shown, $plainSettings, $andBackups), $cfg, $notFound);
        }

        // The probe: the guard line, then a token only a server that hands
        // out PHP files as text would show.
        foreach (glob($appRoot . '/bc-probe-*.php') ?: [] as $old) {
            if ((int) @filemtime($old) < time() - 120) {
                @unlink($old); // left by a check that was cut short; a running one is younger
            }
        }
        $token = bin2hex(random_bytes(16));
        $probe = 'bc-probe-' . bin2hex(random_bytes(8)) . '.php';
        $probePath = $appRoot . '/' . $probe;
        $old = umask(0022);
        $wrote = @file_put_contents($probePath, SettingsFile::GUARD_PREFIX . " // Better-Cal private-files check; deleted after use\n$token\n");
        umask($old);
        if ($wrote === false) {
            return self::finish('unchecked', 'Not checked.', "Better-Cal can't write a test file into its folder, so it couldn't test this. " . self::checkYourself($shown), $cfg, 'unchecked');
        }
        @chmod($probePath, 0644);

        try {
            $findings = self::probe($hosts, $place['path'], $probe, $token, $settingsFiles, $fetch);
        } finally {
            @unlink($probePath);
        }

        $first = static function (string $kind) use ($findings): ?string {
            foreach ($findings as $host => $f) {
                if ($f === $kind) {
                    return $host;
                }
            }
            return null;
        };
        $docroot = $place['docroot'] ?? 'the web folder it sits in';
        if (($host = $first('exposed')) !== null) {
            return self::finish('exposed', 'Fix this now: your settings can be downloaded.',
                "$host{$place['path']}.env.php shows your database password to anyone, because this server isn't running PHP for that folder. "
                . "Move the $folder folder out of $docroot, or ask your host to turn PHP on for it. " . self::CHANGE_SECRETS, $cfg, 'exposed');
        }
        if (($host = $first('exposed-plain')) !== null) {
            return self::finish('exposed', 'Fix this now: your settings can be downloaded.',
                "$host{$place['path']}.env shows your database password to anyone. Run php server/bin/settings.php --convert as the account that owns .env, "
                . "or move the $folder folder out of $docroot. " . self::CHANGE_SECRETS, $cfg, 'exposed');
        }
        if (($host = $first('php-off')) !== null) {
            return self::finish('probably', 'Probably safe, but fix this.',
                "$host refuses to show your settings file, but it shows Better-Cal's other PHP files as text instead of running them. "
                . "Move the $folder folder out of $docroot, or ask your host to turn PHP on for it.", $cfg, 'php-off');
        }
        if (($host = $first('guarded')) !== null) {
            return self::finish('safe', 'Safe.', "$host can reach the Better-Cal folder, but your settings file shows as a blank page there$andBackups.", $cfg, 'guarded');
        }
        if (($host = $first('refused')) !== null) {
            return self::finish('safe', 'Safe.', "Your Better-Cal folder sits inside $host's public web space, but the server refuses every request for it, so no one can download your settings or backups.", $cfg, 'refused');
        }
        if (count(array_filter($findings, static fn(string $f): bool => $f === 'failed')) === count($findings)) {
            return self::finish('unchecked', 'Not checked.', "Your host doesn't let Better-Cal fetch pages from its own websites, which is common and not a problem in itself. " . self::checkYourself($shown), $cfg, 'unchecked');
        }
        return self::finish('probably', 'Probably safe.', self::probablyText($shown, $plainSettings, $andBackups), $cfg, $notFound);
    }

    /**
     * Ask each host for the probe and for the settings file itself, with a
     * made-up address beside them to tell a real 403 from a domain that
     * refuses everything. The probe says whether PHP runs there; the
     * settings file, whether it can actually be read (a server can hand out
     * PHP files as text and still refuse dotfiles). One finding per host:
     * exposed, exposed-plain, php-off, guarded, refused, missing or failed.
     *
     * @param list<string> $hosts
     * @param list<string> $settingsFiles the ones that exist (.env, .env.php)
     * @return array<string,string>
     */
    private static function probe(array $hosts, string $path, string $probe, string $token, array $settingsFiles, callable $fetch): array
    {
        $missing = 'bc-missing-' . bin2hex(random_bytes(8)) . '.php';
        $asks = [];
        foreach ($hosts as $host) {
            $asks[] = [$host, 'probe', $path . $probe];
            $asks[] = [$host, 'control', '/' . $missing];
            foreach ($settingsFiles as $f) {
                $asks[] = [$host, $f, $path . $f];
            }
        }
        $answers = self::ask($asks, $fetch);
        $findings = [];
        foreach ($hosts as $host) {
            // The settings file that reads, if any (.env first: it isn't guarded).
            $env = null;
            $envFile = null;
            foreach ($settingsFiles as $f) {
                $r = $answers[$host][$f];
                if ($r['status'] === 200 && str_contains($r['body'], 'BETTERCAL_')) {
                    $env = $r;
                    $envFile = $f;
                    break;
                }
                $env ??= $r;
            }
            $findings[$host] = self::classify($answers[$host]['probe'], $answers[$host]['control'], $env, $token, $envFile);
        }
        return $findings;
    }

    /**
     * One host's answers to a finding. Pure; unit-tested.
     *
     * @param array{status:int,body:string} $probe
     * @param array{status:int,body:string} $control
     * @param ?array{status:int,body:string} $env
     */
    public static function classify(array $probe, array $control, ?array $env, string $token, ?string $envFile = null): string
    {
        $settingsRead = $env !== null && $env['status'] === 200 && str_contains((string) $env['body'], 'BETTERCAL_');
        $phpOff = $probe['status'] === 200 && str_contains((string) $probe['body'], $token);
        if ($settingsRead) {
            // Which file answered, not what it holds: a .env copied from the
            // example starts with the guard line too, and still needs converting.
            if ($envFile !== null) {
                return $envFile === '.env' ? 'exposed-plain' : 'exposed';
            }
            return str_contains((string) $env['body'], SettingsFile::GUARD_PREFIX) ? 'exposed' : 'exposed-plain';
        }
        if ($phpOff) {
            return 'php-off';
        }
        $blank = static fn(array $r): bool => $r['status'] === 200 && trim((string) $r['body']) === '';
        if ($blank($probe) && !$blank($control)) {
            return 'guarded';
        }
        if ($probe['status'] === 403 && in_array($control['status'], [404, 410], true)) {
            return 'refused';
        }
        if ($probe['status'] === 0) {
            return 'failed';
        }
        return 'missing';
    }

    /**
     * Fetch every ask over https, then http for those that got no answer,
     * following a redirect once when it keeps the same path (to https, or
     * between example.com and www.example.com).
     *
     * @param list<array{0:string,1:string,2:string}> $asks host, kind, path
     * @return array<string,array<string,array{status:int,body:string}>>
     */
    private static function ask(array $asks, callable $fetch): array
    {
        $urls = array_map(static fn(array $a): string => 'https://' . $a[0] . $a[2], $asks);
        $got = $fetch($urls);
        $retry = [];
        foreach ($asks as $i => $a) {
            $r = $got[$i] ?? ['status' => 0, 'body' => '', 'location' => null];
            if ($r['status'] === 0) {
                $retry[$i] = 'http://' . $a[0] . $a[2];
            } elseif (in_array($r['status'], [301, 302, 303, 307, 308], true) && is_string($r['location'] ?? null)
                && (string) parse_url($r['location'], PHP_URL_PATH) === $a[2]
                && in_array(parse_url($r['location'], PHP_URL_SCHEME), ['http', 'https'], true)
                && self::mainDomain((string) parse_url($r['location'], PHP_URL_HOST)) === self::mainDomain($a[0])) {
                $retry[$i] = $r['location'];
            }
        }
        if ($retry !== []) {
            $again = $fetch(array_values($retry));
            foreach (array_keys($retry) as $n => $i) {
                $r = $again[$n] ?? null;
                if (is_array($r) && ($r['status'] !== 0 || ($got[$i]['status'] ?? 0) === 0)) {
                    $got[$i] = $r;
                }
            }
        }
        $out = [];
        foreach ($asks as $i => $a) {
            $r = $got[$i] ?? ['status' => 0, 'body' => ''];
            $out[$a[0]][$a[1]] = ['status' => (int) $r['status'], 'body' => (string) $r['body']];
        }
        return $out;
    }

    /**
     * Where the main domain would serve the app folder: the part of its
     * path after the last folder hosts use as a web root (public_html,
     * htdocs, a folder named for a domain…). Null when there is none.
     *
     * @return ?array{path:string,docroot:string,domain:?string}
     */
    public static function candidatePath(string $appRoot): ?array
    {
        $segments = array_values(array_filter(explode('/', $appRoot), static fn(string $s): bool => $s !== ''));
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $s = strtolower($segments[$i]);
            $isDomain = preg_match('/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,63}$/', $s) === 1;
            if ($i < count($segments) - 1 && (in_array($s, self::DOCROOTS, true) || $isDomain)) {
                $domain = null;
                for ($j = $i; $j >= 0; $j--) {
                    if (preg_match('/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,63}$/', strtolower($segments[$j])) === 1) {
                        $domain = strtolower($segments[$j]);
                        break;
                    }
                }
                return [
                    'path' => '/' . implode('/', array_slice($segments, $i + 1)) . '/',
                    'docroot' => $segments[$i],
                    'domain' => $domain,
                ];
            }
        }
        return null;
    }

    /**
     * The domains that could serve the app folder: the main domain of the
     * install's own address and its www form (cal.example.com: example.com
     * and www.example.com), and a domain the folder path is named for.
     * Never the install's own address, an IP or a bare public suffix.
     *
     * @return list<string>
     */
    public static function candidateHosts(string $baseHost, ?string $pathDomain): array
    {
        $out = [];
        $main = self::mainDomain($baseHost);
        if ($main !== null && $main !== $baseHost) {
            $out[] = $main;
            if ('www.' . $main !== $baseHost) {
                $out[] = 'www.' . $main;
            }
        }
        if ($pathDomain !== null && $pathDomain !== $baseHost && self::mainDomain($pathDomain) !== null && !in_array($pathDomain, $out, true)) {
            $out[] = $pathDomain;
        }
        return $out;
    }

    /** The registrable domain of a host name (cal.example.co.uk: example.co.uk), or null. */
    public static function mainDomain(string $host): ?string
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_contains($host, ':') || !str_contains($host, '.')) {
            return null;
        }
        foreach (self::SHARED as $shared) {
            if ($host === $shared || str_ends_with($host, '.' . $shared)) {
                return null;
            }
        }
        $labels = explode('.', $host);
        $n = count($labels);
        $suffixLen = in_array($labels[$n - 2] . '.' . $labels[$n - 1], self::TWO_LEVEL, true) ? 2 : 1;
        if ($n <= $suffixLen) {
            return null;
        }
        return implode('.', array_slice($labels, $n - $suffixLen - 1));
    }

    private static function probablyText(string $shown, bool $plainSettings, string $andBackups): string
    {
        $file = $plainSettings ? '.env' : '.env.php';
        $host = explode('/', $shown, 2)[0];
        $path = substr($shown, strlen($host));
        return ($plainSettings ? "Your settings are still in .env$andBackups. " : "Your settings file can't be shown on the web$andBackups. ")
            . "Better-Cal couldn't find which address, if any, serves its folder. If your hosting account's main domain isn't $host, "
            . "open that domain$path$file: a blank page, Forbidden or Not Found is good.";
    }

    private static function checkYourself(string $shown): string
    {
        return "To check yourself, open $shown.env.php: a blank page, Forbidden or Not Found means your settings are safe; "
            . 'if you see lines starting with BETTERCAL_, see ' . self::DOCS . '.';
    }

    /**
     * The result, with notes about other files that could be downloaded
     * where the folder isn't out of reach.
     *
     * @return array{status:string,title:string,message:string,notes:list<string>,kind:string}
     */
    private static function finish(string $status, string $title, string $message, array $cfg, string $kind): array
    {
        $appRoot = rtrim($cfg['app_root'], '/');
        // Files in the folder are worth a note only where it may be served.
        $reachable = !in_array($kind, ['unlikely', 'refused'], true);
        $notes = [];
        $plain = SettingsFile::plain($appRoot);
        $guarded = SettingsFile::guarded($appRoot);
        if (is_file($plain) && is_file($guarded)) {
            $notes[] = 'Your settings are in .env.php, and an older .env with different values is still beside it. Better-Cal uses .env.php; copy anything you still need from .env into it, then delete .env.';
        } elseif (is_file($plain) && $kind !== 'exposed') {
            $notes[] = 'Your settings are still in .env. To move them to .env.php, which a web server shows as a blank page, run as the account that owns .env: php server/bin/settings.php --convert';
        }
        if (is_file($appRoot . '/server/public/error_log')) {
            $notes[] = "PHP has been writing its errors to server/public/error_log, which anyone can download from Better-Cal's own address. Better-Cal now keeps them private; delete that file.";
        }
        if ($reachable) {
            foreach (['error_log', 'server/error_log', 'server/bin/error_log'] as $rel) {
                if (is_file($appRoot . '/' . $rel)) {
                    $notes[] = "PHP has been writing its errors to $rel in the Better-Cal folder, where they could be downloaded. Better-Cal now keeps them private; delete that file.";
                }
            }
            if (is_file($appRoot . '/worker.log')) {
                $notes[] = "Your cron job writes the worker's output to worker.log inside the Better-Cal folder, where it could be downloaded. Change the cron line to: "
                    . Setup::cronLine($appRoot) . ' and then delete worker.log.';
            }
            $updater = new Updater($appRoot);
            $previous = $updater->readState()['previous'] ?? null;
            $plainCopies = array_values(array_filter($updater->siblingCopies(), static fn(string $d): bool => is_file(SettingsFile::plain($d))));
            $others = array_values(array_filter($updater->siblingCopies(), static fn(string $d): bool => $d !== $previous && !in_array($d, $plainCopies, true)));
            if ($plainCopies !== []) {
                $notes[] = 'Other copies of Better-Cal beside this one keep their settings in .env, which may be downloadable (' . implode(', ', array_map('basename', $plainCopies)) . '). Delete the ones you no longer need.';
            }
            if ($others !== []) {
                $notes[] = 'Older copies of Better-Cal sit beside this one (' . implode(', ', array_map('basename', $others)) . '). Delete the ones you no longer need: their code is out of date.';
            }
            foreach ([$updater->parent() . '/' . $updater->name() . '-backups', $appRoot . '/.backups'] as $dir) {
                if ((glob($dir . '/*.sql.gz') ?: []) !== []) {
                    $notes[] = (string) ($cfg['session_secret'] ?? '') === ''
                        ? 'Backups in ' . basename($dir) . " aren't encrypted, because BETTERCAL_SESSION_SECRET is empty. Set it (the installer makes one), then run: php server/bin/settings.php --convert"
                        : 'Some backups in ' . basename($dir) . " aren't encrypted yet. Run as the account that owns them: php server/bin/settings.php --convert";
                    break;
                }
            }
        }
        return ['status' => $status, 'title' => $title, 'message' => $message, 'notes' => $notes, 'kind' => $kind];
    }

    /**
     * Plain concurrent GETs: no redirects followed, short timeouts, the first
     * 64 KB of each body. The hosts are the install's own main domain, not
     * anything a user typed, so the outbound policy's private-address rule
     * doesn't apply (a home server's main domain may resolve to one).
     *
     * @return callable(list<string>):list<array{status:int,body:string,location:?string}>
     */
    public static function fetcher(): callable
    {
        return static function (array $urls): array {
            if (!function_exists('curl_multi_init')) {
                return array_fill(0, count($urls), ['status' => 0, 'body' => '', 'location' => null]);
            }
            $multi = curl_multi_init();
            $handles = [];
            foreach ($urls as $i => $url) {
                $ch = curl_init($url);
                $state = (object) ['body' => '', 'location' => null];
                curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT_MS => 3000,
                    CURLOPT_TIMEOUT_MS => 6000,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_USERAGENT => HttpClient::userAgentFor('private-files check'),
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_PROXY => '',
                    CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
                    CURLOPT_HEADERFUNCTION => static function ($c, string $line) use ($state): int {
                        if (stripos($line, 'location:') === 0) {
                            $state->location = trim(substr($line, 9));
                        }
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use ($state): int {
                        if (strlen($state->body) >= 65536) {
                            return 0;
                        }
                        $state->body .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[$i] = [$ch, $state];
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 0.5);
                }
            } while ($running > 0 && $status === CURLM_OK);
            $out = [];
            foreach ($handles as $i => [$ch, $state]) {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $out[$i] = ['status' => $code, 'body' => $state->body, 'location' => $state->location];
                curl_multi_remove_handle($multi, $ch);
            }
            curl_multi_close($multi);
            return $out;
        };
    }
}
