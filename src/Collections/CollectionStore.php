<?php
declare(strict_types=1);

namespace Bins\Collections;

use PDO;

/** Stored collection days for each street. Days are UK dates ('Y-m-d'). */
final class CollectionStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Replace a street's collections between two dates (inclusive) with fresh ones.
     *
     * @param array<string, list<string>> $days 'Y-m-d' => bins
     */
    public function replaceStreet(string $street, string $from, string $to, array $days, string $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM collections WHERE street = ? AND day >= ? AND day <= ?')
                ->execute([$street, $from, $to]);
            $insert = $this->pdo->prepare('INSERT INTO collections (street, day, bin, fetched_at) VALUES (?, ?, ?, ?)');
            foreach ($days as $day => $bins) {
                foreach ($bins as $bin) {
                    $insert->execute([$street, $day, $bin, $now]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function purgeBefore(string $day): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM collections WHERE day < ?');
        $stmt->execute([$day]);
        return $stmt->rowCount();
    }

    /** @return list<string> bins collected from this street on this day */
    public function onDay(string $street, string $day): array
    {
        $stmt = $this->pdo->prepare('SELECT bin FROM collections WHERE street = ? AND day = ? ORDER BY bin');
        $stmt->execute([$street, $day]);
        return Bins::sort($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The next collection days from a date (inclusive).
     *
     * @return array<string, list<string>> 'Y-m-d' => bins
     */
    public function upcoming(string $street, string $from, int $days = 6, bool $garden = true): array
    {
        $stmt = $this->pdo->prepare('SELECT day, bin FROM collections WHERE street = ? AND day >= ? ORDER BY day');
        $stmt->execute([$street, $from]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$garden && $row['bin'] === 'garden') {
                continue;
            }
            $day = substr((string) $row['day'], 0, 10);
            if (!isset($out[$day]) && count($out) >= $days) {
                break;
            }
            $out[$day][] = $row['bin'];
        }
        return array_map([Bins::class, 'sort'], $out);
    }

    /**
     * The weekday this street's bin is usually collected on (e.g. 'Wednesday'),
     * from the stored calendar, or null if there's too little to tell.
     */
    public function usualWeekday(string $street, string $bin): ?string
    {
        $stmt = $this->pdo->prepare('SELECT day FROM collections WHERE street = ? AND bin = ?');
        $stmt->execute([$street, $bin]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $day) {
            $w = date('l', strtotime(substr((string) $day, 0, 10) . ' 12:00:00'));
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        if (array_sum($counts) < 3) {
            return null;
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /** @return array<string, string> street => latest day stored */
    public function lastDayByStreet(): array
    {
        $rows = $this->pdo->query('SELECT street, MAX(day) AS last FROM collections GROUP BY street')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['street']] = substr((string) $r['last'], 0, 10);
        }
        return $out;
    }
}
