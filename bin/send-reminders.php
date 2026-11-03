<?php
declare(strict_types=1);

/**
 * Send bin reminders. Run from cron every 15 minutes; it only acts between
 * 18:00 and 22:00 (evening reminders for tomorrow) and 06:30 and 08:00 (morning
 * reminders for today), UK time, and never reminds anyone twice.
 *
 *   php bin/send-reminders.php [--dry-run] [--at="2026-11-03 18:05"] [--config=PATH]
 *   php bin/send-reminders.php --test [--street=pelham-crescent] [--timing=morning]
 *
 *   --test      send one sample reminder to the site's own mailbox, marked [TEST];
 *               nothing is recorded and no subscriber is emailed
 *   --dry-run   say what would be sent, send nothing
 *   --at        pretend it is this UK time (for checks; combine with --dry-run)
 *
 * Output never includes subscribers' addresses (it can appear in public logs).
 */

use Bins\Collections\CollectionStore;
use Bins\Mail\LogMailer;
use Bins\Mail\MailerFactory;
use Bins\Park\Streets;
use Bins\Reminders\ReminderBuilder;
use Bins\Reminders\ReminderSender;
use Bins\Storage\Database;
use Bins\Storage\Settings;
use Bins\Subscribers\SignupService;
use Bins\Subscribers\SubscriberStore;
use Bins\Support\Alerter;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['test', 'dry-run', 'at:', 'street:', 'timing:', 'config:']);
$alerter = null;

try {
    $config = bins_config($options['config'] ?? null);
    $alerter = new Alerter($config['alerts']['admin_email'] ?? null, $config['alerts']['from_email'] ?? null);

    $cacheDir = $config['paths']['cache'];
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $lock = fopen($cacheDir . '/reminders.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another reminder run is in progress.\n");
        exit(0);
    }

    try {
        $pdo = Database::connect($config['db']);
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to the database (' . Database::describeError($e) . ')');
    }

    $dryRun = isset($options['dry-run']);
    $mailer = $dryRun ? new LogMailer() : MailerFactory::fromConfig($config);
    $store = new SubscriberStore($pdo);
    $signup = new SignupService($store, new Settings($pdo), $mailer, $config['site']['url']);
    $mail = $config['mail'] ?? [];
    $sender = new ReminderSender($pdo, $store, new CollectionStore($pdo), $signup, $mailer,
        new ReminderBuilder($config['site']['url'], $config['site']['organisation']),
        (int) ($mail['max_per_run'] ?? 300), $dryRun ? 0 : (int) ($mail['pause_ms'] ?? 300));

    $now = gmdate('Y-m-d H:i:s');
    if (isset($options['at'])) {
        $now = (new DateTimeImmutable((string) $options['at'], new DateTimeZone('Europe/London')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    if (isset($options['test'])) {
        $to = $config['alerts']['admin_email'] ?? null;
        if (!$to) {
            throw new RuntimeException('No admin_email in config to send the test to');
        }
        $street = (string) ($options['street'] ?? 'pelham-crescent');
        if (!Streets::exists($street)) {
            throw new RuntimeException("Unknown street: {$street}");
        }
        $timing = ($options['timing'] ?? 'evening') === 'morning' ? 'morning' : 'evening';
        $summary = $sender->test($now, $to, $street, $timing);
    } elseif ($dryRun) {
        $pdo->beginTransaction();       // work it all out, then undo
        $summary = $sender->run($now);
        $pdo->rollBack();
        $summary['log'][] = 'Dry run: nothing was sent or recorded';
    } else {
        $summary = $sender->run($now);
    }

    if ($summary['status'] !== 'idle' || isset($options['at']) || $dryRun) {
        echo gmdate('Y-m-d H:i', strtotime($now . ' UTC')), ' UTC ', implode("\n", $summary['log']), "\n";
    }

    // Alert at most once per reminder window, not on every 15-minute run.
    $settings = new Settings($pdo);
    $alertOnce = static function (string $kind, string $subject, string $body) use ($settings, $alerter, $summary): void {
        $key = 'alerted_' . $kind;
        $window = $summary['timing'] . ' ' . $summary['day'];
        if ($settings->get($key) !== $window) {
            $alerter->send($subject, $body);
            $settings->set($key, $window);
        }
    };
    if ($summary['missing'] && !$dryRun && !isset($options['test'])) {
        $alertOnce('missing', 'Reminders: no calendar for some streets', implode("\n", $summary['log'])
            . "\n\nThe sync has not stored collections far enough ahead for these streets, so their subscribers were not reminded. Check the sync log.\n");
    }
    if (in_array($summary['status'], ['failed', 'partial'], true) && !$dryRun) {
        $alertOnce('failed', 'Reminders not all sent', implode("\n", $summary['log'])
            . "\n\nLater runs in the same window retry (up to 3 times each), without reminding anyone twice.\n");
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    $report = 'FAILED: ' . $e->getMessage() . "\n";
    fwrite(STDERR, $report);
    $alerter?->send('Reminder run failed', $report);
    exit(1);
}
