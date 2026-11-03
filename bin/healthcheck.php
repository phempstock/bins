<?php
declare(strict_types=1);

/**
 * Short status report for the server, used by deploy/ionos.sh.
 *
 *   php bin/healthcheck.php
 *
 * Prints nothing sensitive (no addresses, host names, user names or paths),
 * because its output appears in the public GitHub Actions log.
 */

use Bins\Collections\CollectionStore;
use Bins\Park\Streets;
use Bins\Storage\Database;
use Bins\Storage\Settings;
use Bins\Subscribers\SubscriberStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$lines = [];
$ok = true;

$extensions = array_map(
    static fn(string $e) => $e . (extension_loaded($e) ? ' yes' : ' MISSING'),
    ['curl', 'pdo_mysql', 'json', 'mbstring', 'openssl']
);
$lines[] = 'PHP extensions: ' . implode(', ', $extensions);

try {
    $config = bins_config();
} catch (Throwable $e) {
    echo implode("\n", $lines), "\nconfig.php: missing\n";
    exit(1);
}

try {
    $pdo = Database::connect($config['db']);
    $lines[] = 'Database: connected (' . Database::version($pdo) . ')';
} catch (PDOException $e) {
    echo implode("\n", $lines), "\nDatabase: connection failed (" . Database::describeError($e) . ")\n";
    exit(1);
}

try {
    $run = $pdo->query('SELECT started_at, status, streets_ok, streets_bad FROM sync_runs ORDER BY id DESC LIMIT 1')->fetch();
    $lines[] = $run
        ? sprintf('Last sync: %s UTC, %s (%s streets read, %s kept from before)', $run['started_at'], $run['status'],
            $run['streets_ok'] ?? '-', $run['streets_bad'] ?? '-')
        : 'Last sync: never';
    if (!$run || $run['status'] !== 'ok') {
        $ok = false;
    }
    $failed = $pdo->query("SELECT started_at, message FROM sync_runs WHERE status <> 'ok' AND status <> 'running' ORDER BY id DESC LIMIT 1")->fetch();
    if ($failed) {
        $lines[] = 'Last sync with problems (' . $failed['started_at'] . ' UTC): ' . str_replace("\n", ' / ', (string) $failed['message']);
    }

    $store = new CollectionStore($pdo);
    $last = $store->lastDayByStreet();
    $today = date('Y-m-d');
    $short = array_filter(Streets::names(), static fn($name, $slug) => ($last[$slug] ?? '0000') < date('Y-m-d', strtotime('+14 days')),
        ARRAY_FILTER_USE_BOTH);
    $lines[] = sprintf('Calendars: %d of %d streets stored%s', count(array_intersect_key($last, Streets::names())), count(Streets::names()),
        $short ? '; less than 2 weeks ahead for ' . implode(', ', $short) : ', all at least 2 weeks ahead');
    if ($short) {
        $ok = false;
    }
    $sample = $store->upcoming('pelham-crescent', $today, 3);
    $lines[] = 'Pelham Crescent next: ' . ($sample
        ? implode('; ', array_map(static fn($d, $b) => date('D j M', strtotime($d)) . ' ' . implode('+', $b), array_keys($sample), $sample))
        : 'nothing stored');

    $c = (new SubscriberStore($pdo))->counts();
    $t = (new SubscriberStore($pdo))->timingCounts();
    $open = (new Settings($pdo))->signupOpen();
    $lines[] = sprintf('Sign-up %s; %d subscribers (%d evening, %d morning), %d awaiting confirmation',
        $open ? 'open' : 'closed', $c['active'], $t['evening'], $t['morning'], $c['pending']);
    $r = $pdo->query('SELECT day, COUNT(*) AS n, SUM(CASE WHEN sent_at IS NULL THEN 1 ELSE 0 END) AS unsent FROM reminder_sends GROUP BY day ORDER BY day DESC LIMIT 3')->fetchAll();
    $lines[] = 'Reminders sent: ' . ($r
        ? implode('; ', array_map(static fn($x) => substr((string) $x['day'], 0, 10) . ' ' . ($x['n'] - $x['unsent']) . ($x['unsent'] ? " ({$x['unsent']} failed)" : ''), $r))
        : 'none yet');
    $lines[] = 'Mail settings: ' . (empty($config['mail']['password']) ? 'not set (upload config.mail.php)' : 'set');
} catch (PDOException $e) {
    $lines[] = 'Tables: not created yet (run the setup task)';
    $ok = false;
}

echo implode("\n", $lines), "\n";
exit($ok ? 0 : 1);
