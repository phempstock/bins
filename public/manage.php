<?php
declare(strict_types=1);

/**
 * Change your reminders: street, time, garden waste. Reached from the signed
 * link in every reminder (?s=ID&t=TOKEN), so no password is needed.
 */

use Bins\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

$id = (int) ($_POST['s'] ?? $_GET['s'] ?? 0);
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$signup = Web::signup();

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'unsubscribe') {
        if ($signup->unsubscribe($id, $token, Web::now(), 'manage')) {
            Web::page('You’ve unsubscribed',
                '<p>You won’t receive BINS reminders any more. Your address will be deleted within 30 days.</p>'
                . '<p>Changed your mind? You can <a href="./#signup">sign up again</a> at any time.</p>');
        }
    } else {
        $r = $signup->updatePreferences($id, $token, (string) ($_POST['street'] ?? ''), (string) ($_POST['timing'] ?? ''),
            ($_POST['garden'] ?? '') === 'yes');
        $notice = '<p class="notice ' . ($r['ok'] ? 'is-ok' : 'is-error') . '" role="status">' . Web::e($r['message']) . '</p>';
    }
}

$sub = $signup->checkLink('manage', $id, $token);
if (!$sub || $sub['status'] !== 'active') {
    Web::page('This link doesn’t work',
        '<p>We couldn’t find an active subscription for this link. You may have unsubscribed. You can <a href="./#signup">sign up again</a>.</p>', 404);
}

$evening = $sub['timing'] === 'evening' ? ' checked' : '';
$morning = $sub['timing'] === 'morning' ? ' checked' : '';
$garden = (int) $sub['garden'] ? ' checked' : '';
$hidden = '<input type="hidden" name="s" value="' . $id . '"><input type="hidden" name="t" value="' . Web::e($token) . '">';

Web::page('Your reminders', $notice
    . '<p>Reminders for <strong>' . Web::e($sub['email']) . '</strong>.</p>'
    . '<form method="post" action="manage.php" class="page-form">' . $hidden
    . '<div class="field"><label for="m-street">Street</label><select id="m-street" name="street">'
    . Web::streetOptions((string) $sub['street']) . '</select></div>'
    . '<fieldset class="field"><legend>When should we remind you?</legend>'
    . '<label class="choice"><input type="radio" name="timing" value="evening"' . $evening . '> <span>The evening before, at 6pm</span></label>'
    . '<label class="choice"><input type="radio" name="timing" value="morning"' . $morning . '> <span>Early on the day, at 6:30am</span></label></fieldset>'
    . '<label class="choice"><input type="checkbox" name="garden" value="yes"' . $garden . '> <span>I pay for garden waste collection: remind me about that too</span></label>'
    . '<button type="submit" class="button">Save</button></form>'
    . '<form method="post" action="manage.php" class="page-form unsubscribe-form">' . $hidden
    . '<input type="hidden" name="action" value="unsubscribe">'
    . '<button type="submit" class="button-secondary">Unsubscribe</button></form>');
