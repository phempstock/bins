<?php
declare(strict_types=1);

/**
 * Confirm a sign-up. The emailed link (GET) shows a button; only pressing it (POST)
 * confirms, so email security scanners that follow links can't confirm on someone's behalf.
 */

use Bins\Park\Streets;
use Bins\Subscribers\SignupService;
use Bins\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Web::page('Confirm your email address',
        '<p>Press the button to start receiving BINS bin day reminders.</p>'
        . '<form method="post" action="confirm.php" class="page-form">'
        . '<input type="hidden" name="token" value="' . Web::e($token) . '">'
        . '<button type="submit" class="button">Confirm my email address</button></form>');
}

$result = Web::signup()->confirm($token, Web::now());
if ($result['code'] === 'confirmed') {
    $s = $result['subscriber'];
    Web::page('You’re subscribed',
        '<p>Thank you. You’ll get an email ' . Web::e(SignupService::timingPhrase((string) $s['timing']))
        . ' each time bins are collected on <strong>' . Web::e(Streets::name((string) $s['street'])) . '</strong>.</p>'
        . '<p>Every reminder has links to change your street or time, and to unsubscribe.</p>'
        . '<p><a href="./?street=' . Web::e((string) $s['street']) . '">See your next bin days</a></p>');
}
match ($result['code']) {
    'expired' => Web::page('This link has expired',
        '<p>Confirmation links work for 7 days. <a href="./#signup">Sign up again</a> and we’ll send a new one.</p>', 410),
    default => Web::page('This link doesn’t work',
        '<p>It may have been used already, in which case you’re subscribed. If not, <a href="./#signup">sign up again</a>.</p>', 404),
};
