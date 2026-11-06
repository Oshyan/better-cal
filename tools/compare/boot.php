<?php

declare(strict_types=1);

// The scenario runner for one checkout (issue #203): loads that checkout's
// server, migrates a fresh database with its own migrations, and replays
// each scenario through its own HTTP API in-process: the checkout's
// bc_handle_api (server/src/entry-web.php) is lifted out as it is, its
// Router, Controllers, auth and RequestAuthority included, with only its
// Response::send() calls turned into a capture. Requests authenticate with
// an API token (Bearer), which all three versions take.
//
// Usage: php boot.php <checkout> <label> <outDir> <scenario.json>...
// Env: BC_TEST_DB (host:port, default 127.0.0.1:3306), BC_TEST_DB_USER
// (root), BC_TEST_DB_PASS (''), as the database suite; CMP_DUMP=1 prints
// every response body to stderr. Writes <outDir>/<label>/<scenario id>.json.
//
// A scenario (scenarios/*.json): {id, about, expect151, expectOld, window?,
// steps}. `window` is the GET /events range the snapshots read (two years at
// most; default 2027-01-01 to 2028-12-30). Each step is one of:
//   {do:"calendar", as, name}             a calendar (the account's first is "A")
//   {do:"person", as, name}
//   {do:"create", as, event:{...}}        POST /events; `calendar` and `people` take aliases
//   {do:"alias", as, of, key}             an edited day's own row, by its series and key
//   {do:"edit", event, on?, scope?, set, choices?}
//   {do:"delete", event, on?, scope?}
//   {do:"answer", event, on?, scope?, attendance|relationship}
//   {do:"rejoin", event, on, fields, values}  useSeries on 1.5.1; `values` with scope this before it
//   {do:"undo"}                           POST /undo, as the toast of every version
//   {do:"request", method, path, body?, query?}
//   {do:"snapshot", name, journal?}
// `on` is the occurrence acted on (its key: a UTC instant, or a date for an
// all-day series), sent as instanceStart. Series writes are version-neutral:
// on 1.5.1 scope all and following are previewed and committed through the
// plan, naming `choices` or else each group's preselected option; on 1.3 and
// 1.4 the same body goes as it is. Any step may carry expect:{status}.
//
// A snapshot holds the account's event rows (ids relative to the scenario's
// first event, calendars to its first calendar; bookkeeping columns left
// out, see VOLATILE), their tags, people and trip links, the occurrences
// GET /events returns over the window (includeHidden), and with `journal`
// the mutations rows (entity, op, undone).

if ($argc < 5) {
    fwrite(STDERR, "usage: php boot.php <checkout> <label> <outDir> <scenario.json>...\n");
    exit(2);
}
$checkout = rtrim($argv[1], '/');
$label = $argv[2];
$outDir = rtrim($argv[3], '/') . '/' . $label;
$files = array_slice($argv, 4);
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot make $outDir\n");
    exit(2);
}

[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
$dbUser = getenv('BC_TEST_DB_USER') ?: 'root';
$dbPass = getenv('BC_TEST_DB_PASS') ?: '';
$dbName = 'cmp_scen_' . preg_replace('/[^a-z0-9]/', '', strtolower($label)) . '_' . bin2hex(random_bytes(3));

putenv('BETTERCAL_DB_DSN=' . "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4");
putenv("BETTERCAL_DB_USER=$dbUser");
putenv("BETTERCAL_DB_PASS=$dbPass");
putenv('BETTERCAL_SESSION_SECRET=' . str_repeat('5a', 32));
putenv('BETTERCAL_BASE_URL=http://localhost');
ini_set('log_errors', '1');
ini_set('error_log', $outDir . '/php-errors.log');

require $checkout . '/server/src/bootstrap.php';

// ---- the checkout's API handler, lifted out ------------------------------
$entry = (string) file_get_contents($checkout . '/server/src/entry-web.php');
$from = strpos($entry, 'function bc_handle_api(');
$to = strpos($entry, 'function bc_handle_static(');
if ($from === false || $to === false) {
    fwrite(STDERR, "no bc_handle_api in $checkout\n");
    exit(2);
}
preg_match_all('/^use [^;]+;$/m', substr($entry, 0, $from), $uses);
$fn = substr($entry, $from, $to - $from);
$fn = str_replace('function bc_handle_api(', 'function cmp_handle_api(', $fn);
// One connection for every request: a new Db per request is what the web
// does, but in one process the connections pile up past the server's limit.
$fn = str_replace('$db = new Db($cfg[\'db\']);', '$db = $GLOBALS[\'__cmp_db\'];', $fn, $oneDb);
if ($oneDb !== 1) {
    fwrite(STDERR, "bc_handle_api in $checkout does not open its Db as expected\n");
    exit(2);
}
$fn = str_replace('__DIR__', var_export($checkout . '/server/src', true), $fn);
$fn = preg_replace('/([^\s;][^;\n]*?)->send\(\);/', '$GLOBALS[\'__cmp_response\'] = $1;', $fn);
$lifted = $outDir . '/lifted-api.php';
file_put_contents($lifted, "<?php\n\ndeclare(strict_types=1);\n\n" . implode("\n", $uses[0]) . "\n\n" . $fn . "\n");
require $lifted;

use BetterCal\Http\Request;
use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\Setup;

/** Columns left out of a row snapshot: identity and bookkeeping that differ per run. */
const VOLATILE = ['id', 'user_id', 'uid', 'created_at', 'updated_at', 'score', 'scored_at', 'change_seq', 'geocoded_at', 'dynamic_json', 'filter_eval_json', 'created_via', 'location_placed_at'];

// ---- a fresh database, migrated by this checkout -------------------------
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg = config();
$db = new Db($cfg['db']);
$GLOBALS['__cmp_db'] = $db;
try {
    (new Migrator($db, $checkout . '/server/migrations'))->run(static function (string $line): void {
    });

    $eventColumns = array_column($db->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['events']), 'c');
    file_put_contents($outDir . '/columns.json', json_encode(['events' => $eventColumns], JSON_PRETTY_PRINT));

    foreach ($files as $file) {
        $scenario = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $result = (new ScenarioRun($db, $cfg, $label, is_file($checkout . '/server/src/Domain/Series/Model.php')))->run($scenario);
        file_put_contents($outDir . '/' . $scenario['id'] . '.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fwrite(STDOUT, $label . ' ' . $scenario['id'] . ' ' . ($result['error'] === null ? 'ran' : 'ERROR ' . $result['error']) . "\n");
    }
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$dbName`");
}

// ---------------------------------------------------------------------------


final class ScenarioRun
{
    private int $userId = 0;
    private string $token = '';
    /** @var array<string,int> */
    private array $events = [];
    /** @var array<string,int> */
    private array $calendars = [];
    /** @var array<string,int> */
    private array $people = [];
    private ?int $base = null;
    private int $calBase = 0;
    private bool $plans;

    /** $plans: the checkout has series plans (1.5.1; GET /config seriesPlans), the old versions don't. */
    public function __construct(private Db $db, private array $cfg, private string $label, bool $plans)
    {
        $this->plans = $plans;
    }

    public function run(array $s): array
    {
        $out = ['id' => $s['id'], 'version' => $this->label, 'plans' => $this->plans, 'steps' => [], 'snapshots' => [], 'error' => null];
        $this->userId = Setup::createOwner($this->db, 'owner-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery', 'Sample Owner');
        $this->token = (new \BetterCal\Domain\ApiTokens($this->db))->create($this->userId, 'Scenario runner')['token'];
        $this->calendars['A'] = (int) $this->db->scalar('SELECT id FROM calendars WHERE user_id = ? ORDER BY id LIMIT 1', [$this->userId]);
        $this->calBase = $this->calendars['A'];
        $window = $s['window'] ?? ['2027-01-01T00:00:00Z', '2028-12-30T00:00:00Z'];
        foreach ($s['steps'] as $i => $step) {
            try {
                $rec = $this->step($step, $window);
            } catch (\Throwable $e) {
                $rec = ['do' => $step['do'] ?? '?', 'exception' => get_class($e) . ': ' . $e->getMessage()];
                $out['error'] = "step $i: " . $rec['exception'];
            }
            $rec['i'] = $i;
            if (($rec['do'] ?? '') === 'snapshot' && isset($rec['snapshot'])) {
                $out['snapshots'][$step['name']] = $rec['snapshot'];
                unset($rec['snapshot']);
            }
            $out['steps'][] = $rec;
            if ($out['error'] !== null) {
                break;
            }
        }
        return $out;
    }

    /** One request through the checkout's API handler: [status, decoded body, headers]. */
    private function http(string $method, string $path, array $body = [], array $query = []): array
    {
        $GLOBALS['__cmp_response'] = null;
        $req = new Request($method, '/api/v1' . $path, $query, $body, ['authorization' => 'Bearer ' . $this->token, 'content-type' => 'application/json']);
        cmp_handle_api($req, $this->cfg);
        $resp = $GLOBALS['__cmp_response'];
        if ($resp === null) {
            return [0, null, []];
        }
        return [$resp->status, json_decode($resp->body, true), $resp->headers];
    }

    private function call(array &$rec, string $method, string $path, array $body = [], array $query = []): array
    {
        [$status, $json] = $this->http($method, $path, $body, $query);
        if (getenv('CMP_DUMP')) {
            fwrite(STDERR, json_encode($json) . "\n");
        }
        $entry = ['method' => $method, 'path' => $path, 'body' => $body, 'status' => $status];
        if ($status >= 400) {
            $entry['error'] = $json['error']['code'] ?? null;
            $entry['message'] = $json['error']['message'] ?? null;
        }
        $rec['requests'][] = $entry;
        return [$status, $json];
    }

    private function eventId(string $alias): int
    {
        if (!isset($this->events[$alias])) {
            throw new \RuntimeException("no event alias $alias");
        }
        return $this->events[$alias];
    }

    /** A scenario's fields with its aliases resolved (calendar, people). */
    private function fields(array $f): array
    {
        if (isset($f['calendar'])) {
            $f['calendarId'] = $this->calendars[$f['calendar']];
            unset($f['calendar']);
        }
        if (isset($f['people'])) {
            $f['personIds'] = array_map(fn(string $p): int => $this->people[$p], $f['people']);
            unset($f['people']);
        }
        return $f;
    }

    private function step(array $step, array $window): array
    {
        $rec = ['do' => $step['do'], 'requests' => []];
        switch ($step['do']) {
            case 'calendar':
                [$st, $j] = $this->call($rec, 'POST', '/calendars', ['name' => $step['name'], 'color' => '#33aa66'] + ($step['fields'] ?? []));
                $this->calendars[$step['as']] = (int) ($j['id'] ?? $j['calendar']['id'] ?? 0);
                break;
            case 'person':
                [$st, $j] = $this->call($rec, 'POST', '/people', ['name' => $step['name']]);
                $this->people[$step['as']] = (int) ($j['id'] ?? 0);
                break;
            case 'create':
                [$st, $j] = $this->call($rec, 'POST', '/events', $this->fields($step['event'] + ['calendar' => 'A']));
                $id = (int) ($j['eventId'] ?? $j['event']['eventId'] ?? 0);
                $this->events[$step['as']] = $id;
                $this->base ??= $id;
                break;
            case 'alias':
                // An edited day's own row, by its series and key.
                $master = $this->eventId($step['of']);
                $key = self::dbTime($step['key']);
                $id = $this->db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$master, $key]);
                $rec['found'] = $id !== null;
                if ($id === null) {
                    throw new \RuntimeException("no edited day of {$step['of']} at {$step['key']}");
                }
                $this->events[$step['as']] = (int) $id;
                break;
            case 'edit':
                $body = $this->fields($step['set'] ?? []);
                if (isset($step['scope'])) {
                    $body['scope'] = $step['scope'];
                }
                if (isset($step['on'])) {
                    $body['instanceStart'] = $step['on'];
                }
                $this->write($rec, 'PATCH', '/events/' . $this->eventId($step['event']), $body, $step);
                break;
            case 'rejoin':
                // "Use series value" (1.5.1); before it, the day's fields set back to the series' values.
                $body = ['scope' => 'this'] + (isset($step['on']) ? ['instanceStart' => $step['on']] : []);
                $body += $this->plans ? ['useSeries' => $step['fields']] : $this->fields($step['values']);
                $this->call($rec, 'PATCH', '/events/' . $this->eventId($step['event']), $body);
                break;
            case 'delete':
                $body = [];
                if (isset($step['scope'])) {
                    $body['scope'] = $step['scope'];
                }
                if (isset($step['on'])) {
                    $body['instanceStart'] = $step['on'];
                }
                $this->write($rec, 'DELETE', '/events/' . $this->eventId($step['event']), $body, $step);
                break;
            case 'answer':
                $kind = isset($step['relationship']) ? 'relationship' : 'attendance';
                $body = [$kind => $step[$kind]];
                if (isset($step['scope'])) {
                    $body['scope'] = $step['scope'];
                }
                if (isset($step['on'])) {
                    $body['instanceStart'] = $step['on'];
                }
                $this->write($rec, 'POST', '/events/' . $this->eventId($step['event']) . '/' . $kind, $body, $step);
                break;
            case 'undo':
                $this->call($rec, 'POST', '/undo');
                break;
            case 'request':
                $this->call($rec, $step['method'], $step['path'], $step['body'] ?? [], $step['query'] ?? []);
                break;
            case 'snapshot':
                $rec['snapshot'] = $this->snapshot($window, (bool) ($step['journal'] ?? false));
                return $rec;
            default:
                throw new \RuntimeException('unknown step ' . $step['do']);
        }
        $last = end($rec['requests']);
        if (isset($step['expect']['status']) && $last !== false && $last['status'] !== $step['expect']['status']) {
            $rec['unexpected'] = 'status ' . $last['status'] . ', expected ' . $step['expect']['status'];
        }
        return $rec;
    }

    /**
     * A series write. On 1.5.1 a whole-series or "this and following" change
     * is previewed, then committed with the plan's hash and an option for
     * every group (the scenario's choice, else the preselected one), as the
     * app's dialog does; a plan-less path answered 409 series_plan_required
     * goes through the plan it carries. On 1.3 and 1.4 the body goes as is.
     */
    private function write(array &$rec, string $method, string $path, array $body, array $step): void
    {
        $scope = $body['scope'] ?? null;
        $planned = $this->plans && ($scope === 'all' || $scope === 'following') && !($method === 'DELETE' && $scope === 'all');
        if (!$planned) {
            [$st, $j] = $this->call($rec, $method, $path, $body);
            if ($this->plans && $st === 409 && ($j['error']['code'] ?? '') === 'series_plan_required' && isset($j['error']['plan'])) {
                $this->commit($rec, $method, $path, $body, $j['error']['plan'], $step['choices'] ?? []);
            }
            return;
        }
        $choices = $step['choices'] ?? [];
        [$st, $j] = $this->call($rec, $method, $path, $body + ['preview' => true, 'plan' => ['choices' => $choices]]);
        if ($st === 400 && ($j['error']['code'] ?? '') === 'preview_unavailable') {
            [$st, $j] = $this->call($rec, $method, $path, $body);
            if ($st === 409 && ($j['error']['code'] ?? '') === 'series_plan_required' && isset($j['error']['plan'])) {
                $this->commit($rec, $method, $path, $body, $j['error']['plan'], $choices);
            }
            return;
        }
        if ($st !== 200 || !isset($j['plan'])) {
            return;
        }
        $plan = $j['plan'];
        if (!empty($plan['whole']) && !empty($plan['direct'])) {
            // Delete or answer from the first day: the whole series', no plan.
            $rec['plan'] = ['whole' => true];
            $this->call($rec, $method, $path, $body);
            return;
        }
        $sel = $choices;
        foreach ($plan['groups'] ?? [] as $g) {
            $sel[$g['id']] ??= $g['selected'];
        }
        // Re-preview with every group named: a pick can bring groups of its own.
        [$st, $j] = $this->call($rec, $method, $path, $body + ['preview' => true, 'plan' => ['choices' => $sel]]);
        if ($st !== 200 || !isset($j['plan'])) {
            return;
        }
        $this->commit($rec, $method, $path, $body, $j['plan'], $sel);
    }

    private function commit(array &$rec, string $method, string $path, array $body, array $plan, array $choices): void
    {
        $sel = $choices;
        foreach ($plan['groups'] ?? [] as $g) {
            $sel[$g['id']] ??= $g['selected'];
        }
        $rec['plan'] = [
            'direct' => $plan['direct'] ?? null,
            'groups' => array_map(static fn(array $g): array => [
                'id' => $g['id'],
                'selected' => $g['selected'],
                'options' => array_map(static fn(array $o): array => ['id' => $o['id'], 'disabled' => (bool) ($o['disabled'] ?? false)], $g['options']),
            ], $plan['groups'] ?? []),
            'chosen' => $sel,
            'refusals' => array_map(static fn(array $r): string => $r['code'], $plan['refusals'] ?? []),
            'summary' => $plan['summary'] ?? null,
        ];
        $this->call($rec, $method, $path, $body + ['plan' => ['hash' => $plan['hash'] ?? null, 'choices' => $sel]]);
    }

    private static function dbTime(string $iso): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return $iso . ' 00:00:00';
        }
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function rel(mixed $id): ?int
    {
        return $id === null ? null : (int) $id - (int) $this->base;
    }

    private function snapshot(array $window, bool $journal): array
    {
        $rows = $this->db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$this->userId]);
        $events = [];
        foreach ($rows as $r) {
            $row = ['rel' => $this->rel($r['id'])];
            foreach ($r as $k => $v) {
                if (in_array($k, VOLATILE, true)) {
                    continue;
                }
                if ($k === 'calendar_id') {
                    $v = (int) $v - $this->calBase;
                } elseif ($k === 'recurrence_parent_id') {
                    $v = $this->rel($v);
                } elseif ($k === 'deleted_at') {
                    $v = $v === null ? null : 'deleted';
                } elseif (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
                    $d = json_decode($v, true);
                    if (is_array($d)) {
                        if (array_is_list($d)) {
                            sort($d);
                        }
                        $v = $d;
                    }
                }
                $row[$k] = $v;
            }
            $events[] = $row;
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $in = $ids === [] ? '(0)' : '(' . implode(',', $ids) . ')';
        $tags = array_map(fn(array $t): array => [$this->rel($t['event_id']), $t['name']],
            $this->db->all("SELECT et.event_id, t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id IN $in ORDER BY et.event_id, t.name"));
        $people = array_map(fn(array $t): array => [$this->rel($t['event_id']), $t['name']],
            $this->db->all("SELECT ep.event_id, p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id IN $in ORDER BY ep.event_id, p.name"));
        $links = array_map(fn(array $t): array => [$this->rel($t['container_id'] ?? $t['trip_id'] ?? null), $this->rel($t['member_id'] ?? $t['event_id'] ?? null)],
            $this->db->all("SELECT * FROM event_links WHERE " . self::linkWhere($this->db) . " IN $in"));

        $rec = [];
        [$st, $j] = $this->call($rec, 'GET', '/events', [], ['start' => $window[0], 'end' => $window[1], 'includeHidden' => '1']);
        $occ = [];
        foreach (($j['events'] ?? []) as $o) {
            $occ[] = [
                'start' => self::utc((string) $o['start'], (bool) ($o['allDay'] ?? false)),
                'end' => self::utc((string) $o['end'], (bool) ($o['allDay'] ?? false)),
                'allDay' => (bool) ($o['allDay'] ?? false),
                'title' => $o['title'] ?? null,
                'calendar' => (int) ($o['calendarId'] ?? 0) - $this->calBase,
                'attendance' => $o['attendance'] ?? 'none',
                'status' => $o['status'] ?? 'confirmed',
                'location' => $o['location'] ?? null,
                'tags' => self::sorted($o['tags'] ?? []),
                'people' => self::sorted($o['people'] ?? []),
            ];
        }
        usort($occ, static fn(array $a, array $b): int => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
        $snap = ['events' => $events, 'tags' => $tags, 'people' => $people, 'links' => $links, 'occurrences' => $occ, 'occurrencesStatus' => $st];
        if ($journal) {
            $snap['journal'] = array_map(static fn(array $m): array => [$m['entity'], $m['op'], (int) $m['undone']],
                $this->db->all('SELECT entity, op, undone FROM mutations WHERE user_id = ? ORDER BY id', [$this->userId]));
        }
        return $snap;
    }

    private static function linkWhere(Db $db): string
    {
        $cols = array_column($db->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['event_links']), 'c');
        return in_array('container_id', $cols, true) ? 'container_id' : (in_array('trip_id', $cols, true) ? 'trip_id' : 'event_id');
    }

    private static function sorted(array $list): array
    {
        $names = array_map(static fn(mixed $x): string => is_array($x) ? (string) ($x['name'] ?? json_encode($x)) : (string) $x, $list);
        sort($names);
        return $names;
    }

    private static function utc(string $iso, bool $allDay): string
    {
        if ($allDay) {
            return substr($iso, 0, 10);
        }
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
