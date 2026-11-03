<?php
declare(strict_types=1);

namespace Bins\Reminders;

use Bins\Collections\CollectionStore;
use Bins\Mail\Mailer;
use Bins\Park\Streets;
use Bins\Subscribers\SignupService;
use Bins\Subscribers\SubscriberStore;
use PDO;

/**
 * Sends reminders. Run every 15 minutes from cron; each run looks at the UK time:
 *
 *   18:00–21:59  "evening" subscribers, for tomorrow's collections
 *   06:30–07:59  "morning" subscribers, for today's collections
 *
 * Outside those windows it does nothing. Every send is recorded, so later runs in
 * the same window never remind anyone twice, and they retry failures (up to
 * MAX_ATTEMPTS). A run stops after STOP_AFTER_FAILURES failures in a row.
 */
final class ReminderSender
{
    public const WINDOWS = [
        'evening' => ['18:00', '22:00', 1],   // start, end (exclusive), collection day = today + 1
        'morning' => ['06:30', '08:00', 0],
    ];
    public const MAX_ATTEMPTS = 3;
    public const STOP_AFTER_FAILURES = 3;

    /** @var list<string> */
    private array $log = [];

    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private PDO $pdo,
        private SubscriberStore $subscribers,
        private CollectionStore $collections,
        private SignupService $signup,
        private Mailer $mailer,
        private ReminderBuilder $builder,
        private int $maxPerRun = 300,
        private int $pauseMs = 300,
        ?callable $sleep = null
    ) {
        $this->sleep = $sleep ?? static fn(int $ms) => usleep($ms * 1000);
    }

    /** Which window (if any) a UK local time falls in: ['evening', '2026-11-04'] or null. */
    public static function windowAt(string $nowUtc): ?array
    {
        $local = (new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/London'));
        $hm = $local->format('H:i');
        foreach (self::WINDOWS as $timing => [$start, $end, $offset]) {
            if ($hm >= $start && $hm < $end) {
                return [$timing, $local->modify('+' . $offset . ' day')->format('Y-m-d')];
            }
        }
        return null;
    }

    /**
     * @return array{status: string, timing: ?string, day: ?string, sent: int, failed: int, remaining: int, missing: list<string>, log: list<string>}
     */
    public function run(string $nowUtc): array
    {
        $window = self::windowAt($nowUtc);
        if ($window === null) {
            $this->note('Outside the reminder times (18:00–22:00 and 06:30–08:00 UK time); nothing to do');
            return $this->summary('idle', null, null, 0, 0, 0, []);
        }
        [$timing, $day] = $window;

        $lastDay = $this->collections->lastDayByStreet();
        $todo = [];
        $missing = [];
        foreach ($this->subscribers->active($timing) as $sub) {
            $street = (string) $sub['street'];
            if (($lastDay[$street] ?? '0000-00-00') < $day) {
                $missing[$street] = Streets::name($street);   // no calendar that far ahead: can't say
                continue;
            }
            $bins = $this->collections->onDay($street, $day);
            if (!(int) $sub['garden']) {
                $bins = array_values(array_diff($bins, ['garden']));
            }
            if ($bins) {
                $todo[] = $sub + ['bins' => $bins];
            }
        }

        $state = $this->sendState($day);
        $todo = array_values(array_filter($todo, static function (array $s) use ($state): bool {
            $row = $state[(int) $s['id']] ?? null;
            return $row === null || ($row['sent_at'] === null && (int) $row['attempts'] < self::MAX_ATTEMPTS);
        }));

        $this->note(sprintf('%s reminders for %s: %d to send', ucfirst($timing), ReminderBuilder::longDate($day), count($todo)));
        if ($missing) {
            $this->note('No stored calendar reaching ' . $day . ' for: ' . implode(', ', $missing));
        }

        $sent = $failed = $consecutive = 0;
        $usual = [];
        foreach (array_slice($todo, 0, $this->maxPerRun) as $i => $sub) {
            if ($i > 0 && $this->pauseMs > 0) {
                ($this->sleep)($this->pauseMs);
            }
            $street = (string) $sub['street'];
            $usual[$street] ??= $this->usualDays($street);
            $next = $this->collections->upcoming($street, date('Y-m-d', strtotime($day . ' 12:00:00 +1 day')), 1, (bool) (int) $sub['garden']);
            $message = $this->builder->message((string) $sub['email'], $street, $timing, $day, $sub['bins'], $next,
                $usual[$street], $this->signup->manageUrl((int) $sub['id']), $this->signup->unsubscribeUrl((int) $sub['id']));
            try {
                $this->mailer->send($message);
                $this->record((int) $sub['id'], $day, $nowUtc, null);
                $sent++;
                $consecutive = 0;
            } catch (\RuntimeException $e) {
                $this->record((int) $sub['id'], $day, null, $e->getMessage());
                $failed++;
                if (++$consecutive >= self::STOP_AFTER_FAILURES) {
                    $this->note("Stopped after {$consecutive} failures in a row: " . $e->getMessage());
                    break;
                }
            }
        }
        $remaining = count($todo) - $sent;
        $this->note("Sent {$sent}" . ($failed ? ", {$failed} failed" : '') . ($remaining ? ", {$remaining} still to send" : ''));

        $status = $consecutive >= self::STOP_AFTER_FAILURES ? 'failed' : ($failed ? 'partial' : 'ok');
        return $this->summary($status, $timing, $day, $sent, $failed, $remaining, array_values($missing));
    }

    /**
     * Send one sample reminder to an address (the site's own mailbox), for the next
     * collection on a street. Nothing is recorded.
     */
    public function test(string $nowUtc, string $to, string $street, string $timing = 'evening'): array
    {
        $today = (new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        $upcoming = $this->collections->upcoming($street, $today, 2);
        if (!$upcoming) {
            throw new \RuntimeException('No stored collections for ' . Streets::name($street) . '; run the sync first');
        }
        $day = array_key_first($upcoming);
        $bins = $upcoming[$day];
        unset($upcoming[$day]);
        // Subscriber 0 does not exist, so the test's links safely do nothing.
        $this->mailer->send($this->builder->message($to, $street, $timing, $day, $bins, $upcoming, $this->usualDays($street),
            $this->signup->manageUrl(0), $this->signup->unsubscribeUrl(0), true));
        $this->note('Test reminder for ' . Streets::name($street) . ' (' . ReminderBuilder::longDate($day) . ': '
            . implode(', ', $bins) . ') sent to the site’s own mailbox');
        return $this->summary('test', $timing, $day, 1, 0, 0, []);
    }

    /** @return array<string, string> bin => usual weekday */
    private function usualDays(string $street): array
    {
        $out = [];
        foreach (['waste', 'recycling', 'garden', 'food'] as $bin) {
            $w = $this->collections->usualWeekday($street, $bin);
            if ($w !== null) {
                $out[$bin] = $w;
            }
        }
        return $out;
    }

    /** @return array<int, array{sent_at: ?string, attempts: int}> */
    private function sendState(string $day): array
    {
        $stmt = $this->pdo->prepare('SELECT subscriber_id, sent_at, attempts FROM reminder_sends WHERE day = ?');
        $stmt->execute([$day]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['subscriber_id']] = $r;
        }
        return $out;
    }

    private function record(int $subscriberId, string $day, ?string $sentAt, ?string $error): void
    {
        $stmt = $this->pdo->prepare('SELECT attempts FROM reminder_sends WHERE subscriber_id = ? AND day = ?');
        $stmt->execute([$subscriberId, $day]);
        $attempts = (int) ($stmt->fetchColumn() ?: 0) + 1;
        $this->pdo->prepare('DELETE FROM reminder_sends WHERE subscriber_id = ? AND day = ?')->execute([$subscriberId, $day]);
        $this->pdo->prepare('INSERT INTO reminder_sends (subscriber_id, day, attempts, sent_at, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([$subscriberId, $day, $attempts, $sentAt, $error]);
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function summary(string $status, ?string $timing, ?string $day, int $sent, int $failed, int $remaining, array $missing): array
    {
        return ['status' => $status, 'timing' => $timing, 'day' => $day, 'sent' => $sent, 'failed' => $failed,
                'remaining' => $remaining, 'missing' => $missing, 'log' => $this->log];
    }
}
