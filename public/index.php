<?php
declare(strict_types=1);

/**
 * Home page: look up a street's next bin days, and sign up for reminders.
 * Works without JavaScript (?street=slug); the script makes it instant.
 */

use Bins\Collections\Bins;
use Bins\Park\Streets;
use Bins\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();
header('Cache-Control: no-cache');

try {
    $config = Web::config();
} catch (RuntimeException $e) {
    // No config.php yet: still show the page (bin days appear once the sync has run).
    $config = ['site' => ['contact_email' => 'bins@parknews.co.uk', 'organisation' => 'the Nottingham Park Residents\' Association (NPRA)'],
               'paths' => ['public_data' => __DIR__ . '/data']];
}
$contact = Web::e($config['site']['contact_email']);
$org = Web::e($config['site']['organisation']);

$data = null;
$file = rtrim($config['paths']['public_data'], '/') . '/collections.json';
if (is_file($file)) {
    $data = json_decode((string) file_get_contents($file), true);
}

$street = (string) ($_GET['street'] ?? '');
if (!Streets::exists($street)) {
    $street = '';
}

try {
    $open = Web::settings()->signupOpen();
} catch (Throwable $e) {
    $open = false;   // not set up yet, or the database is down: just hide the form
}

/** The next bin days for a street, as an HTML list (also built by app.js). */
function upcomingHtml(?array $data, string $street): string
{
    $next = $data['streets'][$street]['next'] ?? [];
    if (!$next) {
        return '<p class="muted">No collection dates are available for this street just now.</p>';
    }
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $html = '<ol class="days">';
    foreach ($next as $c) {
        $when = $c['day'] === $today ? 'Today' : ($c['day'] === $tomorrow ? 'Tomorrow' : date('l', strtotime($c['day'])));
        $html .= '<li class="day"><span class="day-date"><strong>' . Web::e($when) . '</strong> '
            . Web::e(date('j F', strtotime($c['day']))) . '</span><span class="day-bins">';
        foreach ($c['bins'] as $b) {
            $html .= '<span class="bin bin-' . Web::e($b) . '"><span class="dot" style="background:' . Bins::colour($b) . '"></span>'
                . Web::e(ucfirst(Bins::label($b))) . '</span>';
        }
        $html .= '</span></li>';
    }
    return $html . '</ol>';
}

$options = Web::streetOptions($street);
$upcoming = $street !== '' ? upcomingHtml($data, $street) : '';
$updated = isset($data['updated']) ? date('j F Y', strtotime($data['updated'])) : null;
$signupHidden = $open ? '' : ' hidden';
$closedHidden = $open ? ' hidden' : '';
?>
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BINS – Bin day reminders for The Park</title>
  <meta name="description" content="Which bins go out, and when, for every street in The Park, Nottingham, with an email reminder the evening before or early on the day.">
  <meta name="theme-color" content="#1f3d34">
  <link rel="preload" href="vendor/fonts/alegreya-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="vendor/fonts/alegreya-sans-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <header class="masthead">
    <h1 class="wordmark">BINS</h1>
    <p class="strap">Bin day reminders for The Park</p>
  </header>

  <main class="page">
    <p class="lead">Which bins to put out, and when, for your street in The Park. Sign up and we’ll email you a reminder at the right time, using the council’s own collection calendar, bank holiday changes included.</p>

    <section class="card" aria-labelledby="lookup-heading">
      <h2 id="lookup-heading">Your bin days</h2>
      <form class="lookup" method="get" action="./">
        <label for="street">Street</label>
        <div class="row">
          <select id="street" name="street" data-collections="data/collections.json"><?= $options ?></select>
          <button type="submit" class="button-secondary js-hide">Show</button>
        </div>
      </form>
      <div id="upcoming" aria-live="polite"><?= $upcoming ?></div>
      <p class="small muted">Bins must be out by 7am on collection day. Garden waste is only collected if you pay for the council’s garden waste service.</p>
    </section>

    <section class="card" id="signup" aria-labelledby="signup-heading"<?= $signupHidden ?>>
      <h2 id="signup-heading">Get reminders</h2>
      <form id="signup-form" method="post" action="subscribe.php" novalidate>
        <div class="field">
          <label for="su-email">Email address</label>
          <input id="su-email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="field">
          <label for="su-street">Street</label>
          <select id="su-street" name="street" required><?= $options ?></select>
        </div>
        <fieldset class="field">
          <legend>When should we remind you?</legend>
          <label class="choice"><input type="radio" name="timing" value="evening" checked> <span>The evening before, at 6pm</span></label>
          <label class="choice"><input type="radio" name="timing" value="morning"> <span>Early on the day, at 6:30am</span></label>
        </fieldset>
        <label class="choice"><input type="checkbox" name="garden" value="yes"> <span>I pay for garden waste collection: remind me about that too</span></label>
        <label class="choice"><input type="checkbox" name="consent" value="yes"> <span>Email me bin reminders. I can unsubscribe at any time. See the <a href="privacy.php">privacy notice</a>.</span></label>
        <div class="hp" aria-hidden="true"><label for="su-website">Leave this empty</label><input id="su-website" name="website" tabindex="-1" autocomplete="off"></div>
        <button type="submit" class="button">Sign up</button>
        <p id="signup-result" class="signup-result" role="status" aria-live="polite"></p>
      </form>
    </section>

    <section class="card"<?= $closedHidden ?>>
      <h2>Reminders coming soon</h2>
      <p>Email reminders aren’t open for sign-up yet. Meanwhile, the bin days above are kept up to date every day.</p>
    </section>

    <section class="notes">
      <h2>Good to know</h2>
      <ul>
        <li><strong>Street not listed?</strong> Some streets have only named houses, which we couldn’t check. Email <a href="mailto:<?= $contact ?>"><?= $contact ?></a> and we’ll add yours.</li>
        <li><strong>Flats with shared bins</strong> may have their own arrangements; the reminders follow your street’s council collections.</li>
        <li>Missed collection? Report it to the council at <a href="https://www.nottinghamcity.gov.uk/information-for-residents/bin-and-rubbish-collections/missed-collections/">nottinghamcity.gov.uk</a>.</li>
      </ul>
    </section>
  </main>

  <footer class="footer">
    <p>Run by <?= $org ?>. Collection dates from Nottingham City Council<?= $updated ? ', checked ' . Web::e($updated) : '' ?>, under the Open Government Licence.</p>
    <p><a href="privacy.php">Privacy notice</a> · <a href="https://nimby.parknews.co.uk">NIMBY: planning applications in The Park</a></p>
  </footer>
  <script src="assets/app.js"></script>
</body>
</html>
