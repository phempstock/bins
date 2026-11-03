<?php
declare(strict_types=1);

/**
 * Fill a throwaway site with collection calendars for every street (no network):
 * Wednesday round for most streets, Thursday for Harlaxton Drive, Clinton Terrace
 * and Derby Road, starting from today. Then writes public/data/collections.json.
 * Usage (from the site folder): php tests/browser/seed.php
 */

use Bins\Collections\CollectionStore;
use Bins\Collections\SyncJob;
use Bins\Council\RecollectClient;
use Bins\Park\Streets;
use Bins\Storage\Database;
use Bins\Subscribers\SubscriberStore;
use Bins\Tests\FakeHttpClient;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/FakeHttpClient.php';

$config = bins_config();
$pdo = Database::connect($config['db']);
$http = new FakeHttpClient();
$today = new DateTimeImmutable('today');
foreach (Streets::names() as $slug => $name) {
    $weekday = in_array($slug, ['harlaxton-drive', 'clinton-terrace', 'derby-road'], true) ? 'thursday' : 'wednesday';
    $d = $today->modify('-1 day')->modify('next ' . $weekday);
    $events = [];
    for ($i = 0; $i < 8; $i++) {
        $events[] = FakeHttpClient::event($d->format('Y-m-d'), $slug === 'maria-court' ? 'Residual' : ($i % 2 ? 'Recycling' : 'Residual'));
        if ($i % 4 === 1 && $slug !== 'maria-court') {
            $events[] = FakeHttpClient::event($d->modify('next friday')->format('Y-m-d'), 'Garden');
        }
        $d = $d->modify($slug === 'maria-court' ? '+7 days' : '+7 days');
    }
    foreach (Streets::samplePlaces($slug) as $place) {
        $http->places[$place] = $events;
    }
}
$job = new SyncJob($pdo, new RecollectClient($http), new CollectionStore($pdo), new SubscriberStore($pdo), $config['paths']['public_data'], 0);
$r = $job->run(gmdate('Y-m-d H:i:s'));
echo implode("\n", $r['log']), "\n";
