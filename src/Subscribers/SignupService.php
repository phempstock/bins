<?php
declare(strict_types=1);

namespace Bins\Subscribers;

use Bins\Mail\EmailLayout;
use Bins\Mail\Mailer;
use Bins\Mail\Message;
use Bins\Park\Streets;
use Bins\Storage\Settings;

/**
 * Sign-up with double opt-in, confirmation, changing preferences and unsubscribing.
 *
 * Every outcome of a sign-up shows the same "check your inbox" message, so the
 * form never reveals whether an address is already on the list. Someone already
 * subscribed is emailed a link to change their street or reminder time instead.
 */
final class SignupService
{
    public const CONFIRM_DAYS = 7;
    public const RESEND_MINUTES = 10;
    public const MAX_PER_IP_PER_HOUR = 5;
    public const MAX_PER_HOUR = 60;
    public const TIMINGS = ['evening', 'morning'];

    public const CHECK_INBOX = 'Check your inbox. We’ve sent you a link to confirm your address; it works for 7 days.';

    public function __construct(
        private SubscriberStore $store,
        private Settings $settings,
        private Mailer $mailer,
        private string $siteUrl
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    public function subscribe(string $email, string $street, string $timing, bool $garden, bool $consent,
                              string $honeypot, string $ip, string $now): array
    {
        if (!$this->settings->signupOpen()) {
            return $this->result(false, 'closed', 'Sign-up for bin reminders isn’t open yet.');
        }
        if (trim($honeypot) !== '') {
            return $this->result(true, 'ok', self::CHECK_INBOX);   // a bot: pretend all is well
        }

        $email = self::normaliseEmail($email);
        if ($email === null) {
            return $this->result(false, 'invalid_email', 'Enter a valid email address, like name@example.com.');
        }
        if (!Streets::exists($street)) {
            return $this->result(false, 'street', 'Choose your street from the list.');
        }
        if (!in_array($timing, self::TIMINGS, true)) {
            return $this->result(false, 'timing', 'Choose when you’d like your reminder.');
        }
        if (!$consent) {
            return $this->result(false, 'consent', 'Tick the box to agree to receive the reminders.');
        }

        $t = strtotime($now . ' UTC');
        $hourAgo = gmdate('Y-m-d H:i:s', $t - 3600);
        $ipHash = hash_hmac('sha256', $ip, $this->settings->secret());
        if ($this->store->attemptsSince($hourAgo, $ipHash) >= self::MAX_PER_IP_PER_HOUR
            || $this->store->attemptsSince($hourAgo) >= self::MAX_PER_HOUR) {
            return $this->result(false, 'too_many', 'Too many sign-ups from here just now. Please try again in an hour.');
        }
        $this->store->recordAttempt($ipHash, $now);

        $existing = $this->store->findByEmail($email);
        $recentlyEmailed = $existing && $existing['confirm_sent_at'] !== null
            && strtotime($existing['confirm_sent_at'] . ' UTC') > $t - self::RESEND_MINUTES * 60;

        if ($existing && $existing['status'] === 'active') {
            if (!$recentlyEmailed) {
                try {
                    $this->mailer->send($this->alreadySubscribedMessage($existing));
                    $this->store->touchNotified((int) $existing['id'], $now);
                } catch (\RuntimeException $e) {
                    error_log('BINS: already-subscribed email failed: ' . $e->getMessage());
                }
            }
            return $this->result(true, 'ok', self::CHECK_INBOX);
        }
        if ($existing && $existing['status'] === 'pending' && $recentlyEmailed) {
            return $this->result(true, 'ok', self::CHECK_INBOX);   // just sent one; don't flood their inbox
        }

        $token = bin2hex(random_bytes(24));
        if ($existing) {
            $this->store->makePending((int) $existing['id'], $street, $timing, $garden, hash('sha256', $token), $now);
        } else {
            $this->store->createPending($email, $street, $timing, $garden, hash('sha256', $token), $now);
        }

        try {
            $this->mailer->send($this->confirmationMessage($email, $street, $timing, $token));
        } catch (\RuntimeException $e) {
            error_log('BINS: confirmation email failed: ' . $e->getMessage());
            return $this->result(false, 'send_failed', 'We couldn’t send the confirmation email just now. Please try again later.');
        }
        return $this->result(true, 'ok', self::CHECK_INBOX);
    }

    /**
     * @return array{ok: bool, code: string, subscriber?: array} code: confirmed | invalid | expired
     */
    public function confirm(string $token, string $now): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $row = $this->store->findByConfirmHash(hash('sha256', $token));
        if (!$row) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        if (strtotime($row['confirm_sent_at'] . ' UTC') < strtotime($now . ' UTC') - self::CONFIRM_DAYS * 86400) {
            return ['ok' => false, 'code' => 'expired'];
        }
        $this->store->activate((int) $row['id'], $now);
        return ['ok' => true, 'code' => 'confirmed', 'subscriber' => $this->store->find((int) $row['id'])];
    }

    public function unsubscribeUrl(int $subscriberId): string
    {
        return $this->siteUrl . '/unsubscribe.php?s=' . $subscriberId . '&t=' . $this->token('unsubscribe', $subscriberId);
    }

    public function manageUrl(int $subscriberId): string
    {
        return $this->siteUrl . '/manage.php?s=' . $subscriberId . '&t=' . $this->token('manage', $subscriberId);
    }

    /** The subscriber for a valid signed link ('unsubscribe' or 'manage'), or null. */
    public function checkLink(string $purpose, int $subscriberId, string $token): ?array
    {
        if ($subscriberId <= 0 || !hash_equals($this->token($purpose, $subscriberId), $token)) {
            return null;
        }
        return $this->store->find($subscriberId);
    }

    public function unsubscribe(int $subscriberId, string $token, string $now, string $purpose = 'unsubscribe'): bool
    {
        $row = $this->checkLink($purpose, $subscriberId, $token);
        if (!$row) {
            return false;
        }
        if ($row['status'] !== 'unsubscribed') {
            $this->store->unsubscribe($subscriberId, $now);
        }
        return true;
    }

    /**
     * Change street, timing or garden waste from a manage link.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function updatePreferences(int $subscriberId, string $token, string $street, string $timing, bool $garden): array
    {
        $row = $this->checkLink('manage', $subscriberId, $token);
        if (!$row || $row['status'] !== 'active') {
            return $this->result(false, 'invalid', 'This link doesn’t work any more. You may have unsubscribed.');
        }
        if (!Streets::exists($street)) {
            return $this->result(false, 'street', 'Choose your street from the list.');
        }
        if (!in_array($timing, self::TIMINGS, true)) {
            return $this->result(false, 'timing', 'Choose when you’d like your reminder.');
        }
        $this->store->updatePreferences($subscriberId, $street, $timing, $garden);
        return $this->result(true, 'saved', 'Saved. Your reminders are for ' . Streets::name($street) . ', '
            . self::timingPhrase($timing) . '.');
    }

    public static function timingPhrase(string $timing): string
    {
        return $timing === 'morning' ? 'at 6:30am on collection day' : 'at 6pm the evening before';
    }

    public static function normaliseEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        // Lower-cased throughout: mail systems treat Jane.Doe@ and jane.doe@ as the same
        // person, and so must we, or one person could be subscribed twice.
        return mb_strtolower($email);
    }

    private function token(string $purpose, int $subscriberId): string
    {
        return substr(hash_hmac('sha256', $purpose . ':' . $subscriberId, $this->settings->secret()), 0, 32);
    }

    private function confirmationMessage(string $email, string $street, string $timing, string $token): Message
    {
        $url = $this->siteUrl . '/confirm.php?token=' . $token;
        $what = 'an email ' . self::timingPhrase($timing) . ' each time bins are collected on ' . Streets::name($street)
            . ', saying which bins to put out';
        $text = "Hello,\n\n"
            . "Someone, hopefully you, asked for BINS bin day reminders: {$what}.\n\n"
            . "To start receiving them, confirm your address within 7 days:\n{$url}\n\n"
            . "If this wasn’t you, ignore this email. Your address will be deleted within 7 days and you won’t hear from us again.\n\n"
            . "BINS – Bin day reminders for The Park\n{$this->siteUrl}\n";

        $content = '<p style="margin:0 0 16px">Hello,</p>'
            . '<p style="margin:0 0 16px">Someone, hopefully you, asked for BINS bin day reminders: ' . EmailLayout::e($what) . '.</p>'
            . '<p style="margin:0 0 20px">To start receiving them, confirm your address within 7 days.</p>'
            . '<p style="margin:0 0 24px">' . EmailLayout::button($url, 'Confirm my email address') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;color:#56635e">If the button doesn’t work, copy this link into your browser:<br>'
            . '<span style="word-break:break-all">' . EmailLayout::e($url) . '</span></p>';
        $footer = 'If this wasn’t you, ignore this email. Your address will be deleted within 7 days and you won’t hear from us again.';

        return new Message($email, 'Confirm your BINS bin day reminders', $text,
            EmailLayout::wrap($content, $footer, 'Confirm your address to start receiving bin day reminders.'));
    }

    private function alreadySubscribedMessage(array $sub): Message
    {
        $manage = $this->manageUrl((int) $sub['id']);
        $now = Streets::name((string) $sub['street']) . ', ' . self::timingPhrase((string) $sub['timing']);
        $text = "Hello,\n\nSomeone, hopefully you, tried to sign this address up for BINS bin day reminders. "
            . "You’re already subscribed: your reminders are for {$now}.\n\n"
            . "To change your street or reminder time, or to unsubscribe:\n{$manage}\n\n"
            . "If this wasn’t you, you can ignore this email; nothing has changed.\n\n"
            . "BINS – Bin day reminders for The Park\n{$this->siteUrl}\n";
        $content = '<p style="margin:0 0 16px">Hello,</p>'
            . '<p style="margin:0 0 16px">Someone, hopefully you, tried to sign this address up for BINS bin day reminders. '
            . 'You’re already subscribed: your reminders are for <strong>' . EmailLayout::e($now) . '</strong>.</p>'
            . '<p style="margin:0 0 24px">' . EmailLayout::button($manage, 'Change my reminders') . '</p>';
        return new Message((string) $sub['email'], 'You’re already subscribed to BINS', $text,
            EmailLayout::wrap($content, 'If this wasn’t you, you can ignore this email; nothing has changed.'));
    }

    private function result(bool $ok, string $code, string $message): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message];
    }
}
