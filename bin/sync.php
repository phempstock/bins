<?php
declare(strict_types=1);

/**
 * Daily: read the next eight weeks of collections for every street from the
 * council's calendar, and tidy away old data. Run from cron, e.g. twice a day.
 *
 *   php bin/sync.php [--config=PATH]
 *
 * Emails the admin if any street's calendar couldn't be read.
 */

use Bins\Collections\CollectionStore;
use Bins\Collections\SyncJob;
use Bins\Council\RecollectClient;
use Bins\Http\CurlHttpClient;
use Bins\Storage\Database;
use Bins\Subscribers\SubscriberStore;
use Bins\Support\Alerter;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['config:']);
$alerter = null;

try {
    $config = bins_config($options['config'] ?? null);
    $alerter = new Alerter($config['alerts']['admin_email'] ?? null, $config['alerts']['from_email'] ?? null);
    try {
        $pdo = Database::connect($config['db']);
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to the database (' . Database::describeError($e) . ')');
    }

    $r = $config['recollect'];
    $job = new SyncJob(
        $pdo,
        new RecollectClient(new CurlHttpClient(30, 3), $r['base_url'], (int) $r['service_id']),
        new CollectionStore($pdo),
        new SubscriberStore($pdo),
        $config['paths']['public_data'],
        (int) $r['pause_ms']
    );
    $summary = $job->run(gmdate('Y-m-d H:i:s'));
    echo gmdate('Y-m-d H:i'), ' UTC ', strtoupper($summary['status']), "\n", implode("\n", $summary['log']), "\n";

    if ($summary['status'] !== 'ok') {
        $alerter->send('Collection calendar not fully read', implode("\n", $summary['log'])
            . "\n\nStreets that failed keep their stored collections, which run weeks ahead, so reminders carry on.\n");
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    $report = 'FAILED: ' . $e->getMessage() . "\n";
    fwrite(STDERR, $report);
    $alerter?->send('Collection sync failed', $report);
    exit(1);
}
