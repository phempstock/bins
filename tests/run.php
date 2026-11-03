<?php
declare(strict_types=1);

/**
 * BINS test suite. No framework needed:
 *
 *   php tests/run.php
 *
 * Uses an in-memory SQLite database and a fake ReCollect server. Set BINS_TEST_DSN
 * (plus BINS_TEST_USER and BINS_TEST_PASSWORD) to run against MySQL or MariaDB.
 */

use Bins\Collections\Bins;
use Bins\Collections\CollectionStore;
use Bins\Collections\SyncJob;
use Bins\Council\RecollectClient;
use Bins\Mail\LogMailer;
use Bins\Park\Streets;
use Bins\Reminders\ReminderBuilder;
use Bins\Reminders\ReminderSender;
use Bins\Storage\Database;
use Bins\Storage\Settings;
use Bins\Subscribers\SignupService;
use Bins\Subscribers\SubscriberStore;
use Bins\Tests\FakeHttpClient;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeHttpClient.php';

$failures = 0;
$passes = 0;

function check(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
    } else {
        $failures++;
        echo "  FAIL: {$message}\n";
    }
}

function test(string $name, callable $fn): void
{
    echo "- {$name}\n";
    try {
        $fn();
    } catch (Throwable $e) {
        check(false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

const PELHAM = ['E72185A6-DBFE-11EE-93DE-AC388EA0F4B8', 'E77497DC-DBFE-11EE-95B1-AC388EA0F4B8'];
const HARLAXTON = ['E36883F6-DBFE-11EE-885A-A8388EA0F4B8', 'E36B5360-DBFE-11EE-8FEB-A8388EA0F4B8'];

function testConnection(): PDO
{
    return Database::connect([
        'dsn'      => getenv('BINS_TEST_DSN') ?: 'sqlite::memory:',
        'user'     => getenv('BINS_TEST_USER') ?: null,
        'password' => getenv('BINS_TEST_PASSWORD') ?: null,
    ]);
}

/** A Thursday round (Harlaxton Drive), with Christmas Eve's general waste moved to Wednesday 23 December. */
function thursdayRound(): array
{
    $e = [];
    foreach (['2026-11-12', '2026-11-26', '2026-12-10', '2026-12-23'] as $d) {
        $e[] = FakeHttpClient::event($d, 'Residual');
    }
    foreach (['2026-11-05', '2026-11-19', '2026-12-03', '2026-12-17', '2026-12-31'] as $d) {
        $e[] = FakeHttpClient::event($d, 'Recycling');
    }
    $e[] = FakeHttpClient::event('2026-11-13', 'Garden');
    return $e;
}

function site(): array
{
    $pdo = testConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (getenv('BINS_TEST_DSN')) {
        foreach (['settings', 'collections', 'sync_runs', 'subscribers', 'signup_attempts', 'reminder_sends'] as $t) {
            $pdo->exec("DROP TABLE IF EXISTS {$t}");
        }
    }
    foreach (glob(dirname(__DIR__) . '/migrations/' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '/*.sql') as $file) {
        Database::migrate($pdo, $file);
    }
    $dir = sys_get_temp_dir() . '/bins-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    $http = new FakeHttpClient();
    $http->places[PELHAM[0]] = FakeHttpClient::wednesdayRound();
    $http->places[HARLAXTON[0]] = thursdayRound();
    $council = new RecollectClient($http);
    $collections = new CollectionStore($pdo);
    $settings = new Settings($pdo);
    $settings->set('signup_open', '1');
    $store = new SubscriberStore($pdo);
    $mailer = new LogMailer();
    $signup = new SignupService($store, $settings, $mailer, 'https://bins.parknews.co.uk');
    $streets = ['harlaxton-drive' => 'Harlaxton Drive', 'pelham-crescent' => 'Pelham Crescent'];
    $sync = new SyncJob($pdo, $council, $collections, $store, $dir, 0, null, $streets);
    $sender = new ReminderSender($pdo, $store, $collections, $signup, $mailer,
        new ReminderBuilder('https://bins.parknews.co.uk', 'the NPRA'), 300, 0);
    return compact('pdo', 'dir', 'http', 'council', 'collections', 'settings', 'store', 'mailer', 'signup', 'sync', 'sender', 'streets');
}

function tokenFrom(string $text): string
{
    preg_match('/confirm\.php\?token=([a-f0-9]+)/', $text, $m);
    return $m[1] ?? '';
}

function addActive(array $s, string $email, string $street, string $timing, bool $garden = false, string $ip = '10.0.0.1'): int
{
    $s['signup']->subscribe($email, $street, $timing, $garden, true, '', $ip, '2026-11-01 10:00:00');
    $r = $s['signup']->confirm(tokenFrom(end($s['mailer']->sent)->text), '2026-11-01 10:05:00');
    return (int) $r['subscriber']['id'];
}

$probe = testConnection();
$probeDriver = $probe->getAttribute(PDO::ATTR_DRIVER_NAME);
echo 'Database: ', $probeDriver, ' ', Database::version($probe), "\n\n";
$probe = null;

// ---------------------------------------------------------------------------

test('Street list from the survey', function () {
    $names = Streets::names();
    check(count($names) === 66, '66 streets');
    check(isset($names['pelham-crescent'], $names['harlaxton-drive'], $names['maria-court']), 'key streets present');
    $sorted = array_values($names);
    $copy = $sorted;
    sort($copy);
    check($sorted === $copy, 'alphabetical');
    foreach ($names as $slug => $name) {
        if (count(Streets::samplePlaces($slug)) < 1) {
            check(false, "{$name} has a sample address");
        }
    }
    check(!Streets::exists('tunnel-road'), 'named-house streets not listed');
});

test('Reading a ReCollect calendar', function () {
    $http = new FakeHttpClient();
    $http->places[PELHAM[0]] = [
        FakeHttpClient::event('2026-11-04', 'Residual'),
        FakeHttpClient::event('2026-11-06', 'Garden', 'Recycling'),
        FakeHttpClient::event('2026-11-07', 'Mystery'),
        ['day' => '2026-11-08', 'flags' => [['name' => 'Residual', 'event_type' => 'info']]],
        FakeHttpClient::event('2027-06-01', 'Residual'),      // outside the requested range
        FakeHttpClient::event('2026-11-09', 'Food23L'),
    ];
    $c = new RecollectClient($http);
    $days = $c->collections(PELHAM[0], '2026-11-03', '2026-12-29');
    check(array_keys($days) === ['2026-11-04', '2026-11-06', '2026-11-09'], 'only collection days in range: ' . implode(',', array_keys($days)));
    check($days['2026-11-06'] === ['garden', 'recycling'] || $days['2026-11-06'] === ['recycling', 'garden'], 'two bins on one day');
    check($days['2026-11-09'] === ['food'], 'food caddy recognised');
    check($c->unknownFlags === ['Mystery'], 'unknown collection types reported');
    $p = $http->requests[0][1];
    check($p['after'] === '2026-11-03' && $p['before'] === '2026-12-29' && $p['hide'] === 'reminder_only', 'asks for the date range');
    try {
        $c->collections('not-a-place', '2026-11-03', '2026-12-29');
        check(false, 'bad place ID refused');
    } catch (RuntimeException $e) {
        check(true, '');
    }
});

test('Bin names', function () {
    check(Bins::labels(['garden', 'waste']) === 'general waste and garden waste', 'labels joined in order');
    check(Bins::labels(['recycling']) === 'recycling', 'one label');
    check(Bins::describe('recycling') === 'grey-lidded recycling bin', 'council wording');
});

test('Daily sync stores calendars and writes the public file', function () {
    $s = site();
    $r = $s['sync']->run('2026-11-03 04:23:00');
    check($r['status'] === 'ok' && $r['ok'] === 2, 'both streets read: ' . implode(' / ', $r['log']));
    check($s['collections']->onDay('pelham-crescent', '2026-11-04') === ['waste'], 'Pelham Crescent: general waste on 4 Nov');
    check($s['collections']->onDay('harlaxton-drive', '2026-12-23') === ['waste'], 'Harlaxton Drive: Christmas change stored');
    check($s['collections']->usualWeekday('harlaxton-drive', 'waste') === 'Thursday', 'usual day worked out');
    $json = json_decode((string) file_get_contents($s['dir'] . '/collections.json'), true);
    check($json['streets']['pelham-crescent']['name'] === 'Pelham Crescent', 'public file has street names');
    check(count($json['streets']['pelham-crescent']['next']) === 6, 'six upcoming days per street');
    check($json['streets']['pelham-crescent']['next'][0] === ['day' => '2026-11-04', 'bins' => ['waste']], 'first upcoming day');
    $run = $s['pdo']->query('SELECT status, streets_ok FROM sync_runs')->fetch();
    check($run['status'] === 'ok' && (int) $run['streets_ok'] === 2, 'run recorded');
});

test('Sync falls back to the second address, then keeps old data', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    // First Pelham address disappears; second works.
    $s['http']->places[PELHAM[1]] = FakeHttpClient::wednesdayRound();
    unset($s['http']->places[PELHAM[0]]);
    $r = (new SyncJob($s['pdo'], $s['council'], $s['collections'], $s['store'], $s['dir'], 0, null, $s['streets']))->run('2026-11-04 04:23:00');
    check($r['status'] === 'ok', 'second sample address used');
    // Now Harlaxton fails completely: its stored days stay.
    $s['http']->places[HARLAXTON[0]] = new RuntimeException('HTTP 500');
    $s['http']->places[HARLAXTON[1]] = [];
    $r = (new SyncJob($s['pdo'], $s['council'], $s['collections'], $s['store'], $s['dir'], 0, null, $s['streets']))->run('2026-11-05 04:23:00');
    check($r['status'] === 'partial' && $r['bad'] === 1, 'one street failed: ' . implode(' / ', $r['log']));
    check(str_contains(implode(' ', $r['log']), 'Harlaxton Drive (HTTP 500; no collections listed)'), 'failure explained');
    check($s['collections']->onDay('harlaxton-drive', '2026-11-12') === ['waste'], 'stored collections kept');
});

test('Reminder windows in UK time, summer and winter', function () {
    check(ReminderSender::windowAt('2026-11-03 18:01:00') === ['evening', '2026-11-04'], 'winter 18:01 UK: evening, for tomorrow');
    check(ReminderSender::windowAt('2026-11-03 17:59:00') === null, 'before 6pm: nothing');
    check(ReminderSender::windowAt('2026-11-03 22:00:00') === null, '10pm: window closed');
    check(ReminderSender::windowAt('2026-07-01 17:01:00') === ['evening', '2026-07-02'], 'summer 17:01 UTC is 18:01 BST');
    check(ReminderSender::windowAt('2026-11-04 06:31:00') === ['morning', '2026-11-04'], '06:31: morning, for today');
    check(ReminderSender::windowAt('2026-07-01 05:31:00') === ['morning', '2026-07-01'], 'summer 05:31 UTC is 06:31 BST');
    check(ReminderSender::windowAt('2026-11-04 08:00:00') === null, '8am: too late');
});

test('Sign-up: closed, validation, honeypot', function () {
    $s = site();
    $s['settings']->set('signup_open', '0');
    check($s['signup']->subscribe('a@example.com', 'pelham-crescent', 'evening', false, true, '', '1.1.1.1', '2026-11-03 10:00:00')['code'] === 'closed', 'closed');
    $s['settings']->set('signup_open', '1');
    $f = fn(...$a) => $s['signup']->subscribe(...$a)['code'];
    check($f('nope', 'pelham-crescent', 'evening', false, true, '', '1.1.1.1', '2026-11-03 10:00:00') === 'invalid_email', 'bad email');
    check($f('a@example.com', 'tunnel-road', 'evening', false, true, '', '1.1.1.1', '2026-11-03 10:00:00') === 'street', 'unknown street');
    check($f('a@example.com', 'pelham-crescent', 'noon', false, true, '', '1.1.1.1', '2026-11-03 10:00:00') === 'timing', 'bad timing');
    check($f('a@example.com', 'pelham-crescent', 'evening', false, false, '', '1.1.1.1', '2026-11-03 10:00:00') === 'consent', 'consent needed');
    check($f('a@example.com', 'pelham-crescent', 'evening', false, true, 'http://spam', '1.1.1.1', '2026-11-03 10:00:00') === 'ok'
        && count($s['mailer']->sent) === 0, 'honeypot: pretend, send nothing');
});

test('Sign-up: double opt-in, already subscribed, rate limit', function () {
    $s = site();
    $r = $s['signup']->subscribe('Pat@Example.org', 'harlaxton-drive', 'morning', true, true, '', '2.2.2.2', '2026-11-03 10:00:00');
    check($r['ok'] && count($s['mailer']->sent) === 1, 'confirmation sent');
    $mail = $s['mailer']->sent[0];
    check($mail->to === 'pat@example.org' && str_contains($mail->text, 'Harlaxton Drive') && str_contains($mail->text, '6:30am'), 'confirmation names street and time');
    check($s['store']->findByEmail('pat@example.org')['status'] === 'pending', 'pending until confirmed');
    check($s['signup']->confirm('0' . substr(tokenFrom($mail->text), 1), '2026-11-03 10:01:00')['code'] === 'invalid'
        || tokenFrom($mail->text)[0] === '0', 'wrong token refused');
    $c = $s['signup']->confirm(tokenFrom($mail->text), '2026-11-03 10:02:00');
    check($c['code'] === 'confirmed' && $c['subscriber']['status'] === 'active' && (int) $c['subscriber']['garden'] === 1, 'confirmed, with garden waste');

    // Signing up again while active: an email with the manage link, nothing changed.
    $s['mailer']->sent = [];
    $r = $s['signup']->subscribe('pat@example.org', 'pelham-crescent', 'evening', false, true, '', '2.2.2.2', '2026-11-03 11:00:00');
    check($r['message'] === SignupService::CHECK_INBOX, 'same message as a new sign-up');
    check(count($s['mailer']->sent) === 1 && str_contains($s['mailer']->sent[0]->text, 'manage.php?s='), 'manage link emailed');
    check($s['store']->findByEmail('pat@example.org')['street'] === 'harlaxton-drive', 'preferences unchanged by the form');

    // Rate limit: 5 an hour from one address.
    for ($i = 0; $i < 4; $i++) {
        $s['signup']->subscribe("x{$i}@example.com", 'pelham-crescent', 'evening', false, true, '', '2.2.2.2', '2026-11-03 11:10:00');
    }
    check($s['signup']->subscribe('y@example.com', 'pelham-crescent', 'evening', false, true, '', '2.2.2.2', '2026-11-03 11:20:00')['code'] === 'too_many', 'rate limited');
});

test('Manage link: change preferences, unsubscribe', function () {
    $s = site();
    $id = addActive($s, 'sam@example.com', 'pelham-crescent', 'evening');
    $url = $s['signup']->manageUrl($id);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    check($s['signup']->checkLink('manage', $id, 'wrong') === null, 'bad token refused');
    check($s['signup']->checkLink('unsubscribe', $id, $q['t']) === null, 'manage token is not an unsubscribe token');
    $r = $s['signup']->updatePreferences($id, $q['t'], 'harlaxton-drive', 'morning', true);
    check($r['ok'] && str_contains($r['message'], 'Harlaxton Drive'), 'saved');
    $row = $s['store']->find($id);
    check($row['street'] === 'harlaxton-drive' && $row['timing'] === 'morning' && (int) $row['garden'] === 1, 'stored');
    check(!$s['signup']->updatePreferences($id, $q['t'], 'nowhere', 'morning', true)['ok'], 'unknown street refused');
    check($s['signup']->unsubscribe($id, $q['t'], '2026-11-03 12:00:00', 'manage'), 'unsubscribe from the manage page');
    check($s['store']->find($id)['status'] === 'unsubscribed', 'unsubscribed');
    check(!$s['signup']->updatePreferences($id, $q['t'], 'pelham-crescent', 'evening', false)['ok'], 'no changes once unsubscribed');
});

test('Evening reminders: right people, right bins, once', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    $a = addActive($s, 'a@example.com', 'pelham-crescent', 'evening');
    $b = addActive($s, 'b@example.com', 'pelham-crescent', 'morning', false, '10.0.0.2');
    $c = addActive($s, 'c@example.com', 'harlaxton-drive', 'evening', false, '10.0.0.3');
    $s['mailer']->sent = [];

    $r = $s['sender']->run('2026-11-03 18:01:00');     // Tuesday evening: Wednesday's collections
    check($r['timing'] === 'evening' && $r['day'] === '2026-11-04', 'evening window for Wednesday');
    check($r['sent'] === 1 && count($s['mailer']->sent) === 1 && $s['mailer']->sent[0]->to === 'a@example.com',
        'only the Pelham Crescent evening subscriber (Harlaxton is Thursday, b wants mornings)');
    $m = $s['mailer']->sent[0];
    check($m->subject === 'Bins tomorrow: general waste', 'subject: ' . $m->subject);
    check(str_contains($m->text, 'Green general waste bin') && str_contains($m->text, 'Wednesday 4 November'), 'which bin and when');
    check(str_contains($m->text, 'Next: recycling on Wednesday 11 November'), 'next collection');
    check(str_contains($m->text, 'manage.php?s=' . $a) && isset($m->headers['List-Unsubscribe']), 'manage and unsubscribe links');

    $r = $s['sender']->run('2026-11-03 18:16:00');
    check($r['sent'] === 0 && count($s['mailer']->sent) === 1, 'later run in the window sends nothing again');

    $s['mailer']->sent = [];
    $r = $s['sender']->run('2026-11-04 06:31:00');
    check($r['timing'] === 'morning' && $r['sent'] === 1 && $s['mailer']->sent[0]->to === 'b@example.com', 'morning subscriber the same day');
    check($s['mailer']->sent[0]->subject === 'Bins today: general waste', 'morning subject');

    $s['mailer']->sent = [];
    $r = $s['sender']->run('2026-11-04 18:01:00');
    check($r['sent'] === 1 && $s['mailer']->sent[0]->to === 'c@example.com'
        && $s['mailer']->sent[0]->subject === 'Bins tomorrow: recycling', 'Thursday street reminded on Wednesday evening');
    check($s['sender']->run('2026-11-04 12:00:00')['status'] === 'idle', 'midday: idle');
});

test('Garden waste only for those who asked', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    addActive($s, 'g@example.com', 'pelham-crescent', 'evening', true);
    addActive($s, 'n@example.com', 'pelham-crescent', 'evening', false, '10.0.0.9');
    $s['mailer']->sent = [];
    $r = $s['sender']->run('2026-11-12 18:30:00');   // Thursday evening before Friday 13 Nov garden collection
    check($r['sent'] === 1 && $s['mailer']->sent[0]->to === 'g@example.com', 'only the garden subscriber');
    check($s['mailer']->sent[0]->subject === 'Bins tomorrow: garden waste', 'garden subject');
});

test('Changed collection day is pointed out', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    addActive($s, 'h@example.com', 'harlaxton-drive', 'evening');
    $s['mailer']->sent = [];
    $r = $s['sender']->run('2026-12-22 18:05:00');   // Tuesday 22 Dec: general waste moved to Wednesday 23rd
    check($r['sent'] === 1, 'reminded the evening before the moved collection');
    $m = $s['mailer']->sent[0];
    check($m->subject === 'Bins tomorrow: general waste (Wednesday)', 'subject flags the day: ' . $m->subject);
    check(str_contains($m->text, 'general waste is collected on Wednesday this week, not the usual Thursday'), 'body explains');
});

test('Failures are retried, and missing calendars reported', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    addActive($s, 'f@example.com', 'pelham-crescent', 'evening');
    $s['mailer']->failFor = ['f@example.com'];
    $r = $s['sender']->run('2026-11-03 18:01:00');
    check($r['status'] === 'partial' && $r['failed'] === 1, 'failure recorded');
    $s['mailer']->failFor = [];
    $s['mailer']->sent = [];
    $r = $s['sender']->run('2026-11-03 18:16:00');
    check($r['sent'] === 1 && count($s['mailer']->sent) === 1, 'retried and sent on the next run');
    check((int) $s['pdo']->query("SELECT attempts FROM reminder_sends")->fetchColumn() === 2, 'two attempts recorded');

    // A street with nothing stored that far ahead: not reminded, and reported.
    $r = $s['sender']->run('2027-03-02 18:01:00');
    check($r['sent'] === 0 && $r['missing'] === ['Pelham Crescent'], 'no calendar: reported, not guessed');
});

test('Test reminder goes only to the site mailbox', function () {
    $s = site();
    $s['sync']->run('2026-11-03 04:23:00');
    addActive($s, 'a@example.com', 'pelham-crescent', 'evening');
    $s['mailer']->sent = [];
    $r = $s['sender']->test('2026-11-03 10:00:00', 'bins@parknews.co.uk', 'harlaxton-drive');
    check(count($s['mailer']->sent) === 1 && $s['mailer']->sent[0]->to === 'bins@parknews.co.uk', 'one email to the site');
    check(str_starts_with($s['mailer']->sent[0]->subject, '[TEST] Bins tomorrow: recycling'), 'marked as a test');
    check((int) $s['pdo']->query('SELECT COUNT(*) FROM reminder_sends')->fetchColumn() === 0, 'nothing recorded');
});

test('Old sign-ups and records are deleted as promised', function () {
    $s = site();
    $s['signup']->subscribe('p@example.com', 'pelham-crescent', 'evening', false, true, '', '3.3.3.3', '2026-11-01 10:00:00');
    $id = addActive($s, 'u@example.com', 'pelham-crescent', 'evening', false, '3.3.3.4');
    $s['store']->unsubscribe($id, '2026-11-02 10:00:00');
    $p = $s['store']->purge('2026-11-09 10:00:01');
    check($p['pending'] === 1 && $p['unsubscribed'] === 0, 'unconfirmed deleted after 7 days');
    $p = $s['store']->purge('2026-12-03 10:00:01');
    check($p['unsubscribed'] === 1, 'unsubscribed deleted after 30 days');
});

echo "\n{$passes} checks passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
