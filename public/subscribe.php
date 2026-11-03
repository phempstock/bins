<?php
declare(strict_types=1);

/**
 * Reminder sign-up.
 *   POST email, street, timing, garden, consent, website (honeypot)
 * Answers JSON to the home page's script, or a full page without JavaScript.
 */

use Bins\Web\Web;

require dirname(__DIR__) . '/bootstrap.php';
Web::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./#signup', true, 303);
    exit;
}

$result = Web::signup()->subscribe(
    (string) ($_POST['email'] ?? ''),
    (string) ($_POST['street'] ?? ''),
    (string) ($_POST['timing'] ?? ''),
    ($_POST['garden'] ?? '') === 'yes',
    ($_POST['consent'] ?? '') === 'yes',
    (string) ($_POST['website'] ?? ''),
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    Web::now()
);

$status = $result['ok'] ? 200 : ($result['code'] === 'too_many' ? 429 : ($result['code'] === 'send_failed' ? 503 : 400));
if (Web::wantsJson()) {
    Web::json($result, $status);
}
Web::page(
    $result['ok'] ? 'Check your inbox' : 'Sign-up not completed',
    '<p>' . Web::e($result['message']) . '</p>' . ($result['ok'] ? '' : '<p><a href="./#signup">Try again</a></p>'),
    $status
);
