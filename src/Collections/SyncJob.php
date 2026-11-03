<?php
declare(strict_types=1);

namespace Bins\Collections;

use Bins\Council\RecollectClient;
use Bins\Park\Streets;
use Bins\Subscribers\SubscriberStore;
use PDO;

/**
 * The daily job: fetch the next eight weeks of collections for every street from
 * the council's calendar, store them, and write public/data/collections.json for
 * the website's "your next bin days" lookup.
 *
 * A street whose calendar can't be read keeps the collections already stored, so
 * a council outage never stops reminders (the stored calendar runs weeks ahead).
 */
final class SyncJob
{
    public const DAYS_AHEAD = 56;
    public const KEEP_DAYS = 30;

    /** @var list<string> */
    private array $log = [];

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param array<string, string>|null $streets slug => name; defaults to all of Streets::names()
     */
    public function __construct(
        private PDO $pdo,
        private RecollectClient $council,
        private CollectionStore $store,
        private SubscriberStore $subscribers,
        private string $publicDataDir,
        private int $pauseMs = 500,
        ?callable $sleep = null,
        private ?array $streets = null
    ) {
        $this->sleep = $sleep ?? static fn(int $ms) => usleep($ms * 1000);
        $this->streets ??= Streets::names();
    }

    /**
     * @return array{status: string, ok: int, bad: int, log: list<string>}
     */
    public function run(string $nowUtc): array
    {
        $this->pdo->prepare("INSERT INTO sync_runs (started_at, status) VALUES (?, 'running')")->execute([$nowUtc]);
        $runId = (int) $this->pdo->lastInsertId();

        $today = self::ukDate($nowUtc);
        $to = date('Y-m-d', strtotime($today . ' 12:00:00 +' . self::DAYS_AHEAD . ' days'));
        $ok = $bad = 0;
        $failed = [];
        $first = true;

        foreach ($this->streets as $slug => $name) {
            $days = null;
            $errors = [];
            foreach (Streets::samplePlaces($slug) as $place) {
                if (!$first && $this->pauseMs > 0) {
                    ($this->sleep)($this->pauseMs);
                }
                $first = false;
                try {
                    $found = $this->council->collections($place, $today, $to);
                    if (!$found) {
                        $errors[] = 'no collections listed';
                        continue;
                    }
                    $days = $found;
                    break;
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if ($days === null) {
                $bad++;
                $failed[] = $name . ' (' . ($errors ? implode('; ', array_unique($errors)) : 'no sample address') . ')';
                continue;
            }
            $this->store->replaceStreet($slug, $today, $to, $days, $nowUtc);
            $ok++;
        }

        if ($this->council->unknownFlags) {
            $this->note('Collection types not recognised (ignored): ' . implode(', ', array_unique($this->council->unknownFlags)));
        }
        $this->note("Calendars read for {$ok} of " . count($this->streets) . " streets, " . self::ukDate($nowUtc) . " to {$to}");
        if ($failed) {
            $this->note('Kept the stored collections for: ' . implode('; ', $failed));
        }

        $purged = $this->store->purgeBefore(date('Y-m-d', strtotime($today . ' 12:00:00 -' . self::KEEP_DAYS . ' days')));
        $p = $this->subscribers->purge($nowUtc);
        if ($p['pending'] || $p['unsubscribed']) {
            $this->note("Deleted {$p['pending']} unconfirmed and {$p['unsubscribed']} unsubscribed address(es), as the privacy notice says");
        }

        $this->writePublicFile($today, $nowUtc);

        $status = $bad === 0 ? 'ok' : ($ok >= $bad ? 'partial' : 'failed');
        $this->pdo->prepare('UPDATE sync_runs SET finished_at = ?, status = ?, streets_ok = ?, streets_bad = ?, message = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $status, $ok, $bad, implode("\n", $this->log), $runId]);
        return ['status' => $status, 'ok' => $ok, 'bad' => $bad, 'log' => $this->log];
    }

    /** public/data/collections.json: the next six collection days for each street. */
    public function writePublicFile(string $today, string $nowUtc): void
    {
        $streets = [];
        foreach ($this->streets as $slug => $name) {
            $next = [];
            foreach ($this->store->upcoming($slug, $today, 6) as $day => $bins) {
                $next[] = ['day' => $day, 'bins' => $bins];
            }
            $streets[$slug] = ['name' => $name, 'next' => $next];
        }
        if (!is_dir($this->publicDataDir) && !mkdir($this->publicDataDir, 0755, true) && !is_dir($this->publicDataDir)) {
            throw new \RuntimeException("Cannot create {$this->publicDataDir}");
        }
        $json = json_encode(['updated' => str_replace(' ', 'T', $nowUtc) . 'Z', 'streets' => $streets],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $target = rtrim($this->publicDataDir, '/') . '/collections.json';
        if (file_put_contents($target . '.tmp', $json) === false || !rename($target . '.tmp', $target)) {
            throw new \RuntimeException("Could not write {$target}");
        }
    }

    public static function ukDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }
}
