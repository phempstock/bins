<?php
declare(strict_types=1);

namespace Bins\Subscribers;

use PDO;

/** Database access for subscribers and sign-up attempts. */
final class SubscriberStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM subscribers WHERE email = ?', [$email]);
    }

    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM subscribers WHERE id = ?', [$id]);
    }

    public function findByConfirmHash(string $hash): ?array
    {
        return $this->one("SELECT * FROM subscribers WHERE confirm_hash = ? AND status = 'pending'", [$hash]);
    }

    public function createPending(string $email, string $street, string $timing, bool $garden, string $confirmHash, string $now): int
    {
        $this->pdo->prepare(
            "INSERT INTO subscribers (email, street, timing, garden, status, confirm_hash, confirm_sent_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
        )->execute([$email, $street, $timing, $garden ? 1 : 0, $confirmHash, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /** A pending or unsubscribed address signs up (again): back to pending with a new token. */
    public function makePending(int $id, string $street, string $timing, bool $garden, string $confirmHash, string $now): void
    {
        $this->pdo->prepare(
            "UPDATE subscribers SET status = 'pending', street = ?, timing = ?, garden = ?, confirm_hash = ?,
                    confirm_sent_at = ?, confirmed_at = NULL, unsubscribed_at = NULL WHERE id = ?"
        )->execute([$street, $timing, $garden ? 1 : 0, $confirmHash, $now, $id]);
    }

    /** Remember when we last emailed an active subscriber about their subscription. */
    public function touchNotified(int $id, string $now): void
    {
        $this->pdo->prepare('UPDATE subscribers SET confirm_sent_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function activate(int $id, string $now): void
    {
        $this->pdo->prepare(
            "UPDATE subscribers SET status = 'active', confirm_hash = NULL, confirmed_at = ? WHERE id = ?"
        )->execute([$now, $id]);
    }

    public function updatePreferences(int $id, string $street, string $timing, bool $garden): void
    {
        $this->pdo->prepare('UPDATE subscribers SET street = ?, timing = ?, garden = ? WHERE id = ?')
            ->execute([$street, $timing, $garden ? 1 : 0, $id]);
    }

    public function unsubscribe(int $id, string $now): void
    {
        $this->pdo->prepare(
            "UPDATE subscribers SET status = 'unsubscribed', confirm_hash = NULL, unsubscribed_at = ? WHERE id = ?"
        )->execute([$now, $id]);
    }

    /** @return list<array{id: int, email: string, street: string, timing: string, garden: int}> */
    public function active(?string $timing = null): array
    {
        if ($timing === null) {
            return $this->pdo->query("SELECT id, email, street, timing, garden FROM subscribers WHERE status = 'active' ORDER BY id")->fetchAll();
        }
        $stmt = $this->pdo->prepare("SELECT id, email, street, timing, garden FROM subscribers WHERE status = 'active' AND timing = ? ORDER BY id");
        $stmt->execute([$timing]);
        return $stmt->fetchAll();
    }

    /** @return array<string, int> e.g. ['active' => 12, 'pending' => 1, 'unsubscribed' => 0] */
    public function counts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS n FROM subscribers GROUP BY status')->fetchAll();
        $counts = ['active' => 0, 'pending' => 0, 'unsubscribed' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    /** @return array<string, int> e.g. ['evening' => 10, 'morning' => 4] for active subscribers */
    public function timingCounts(): array
    {
        $rows = $this->pdo->query("SELECT timing, COUNT(*) AS n FROM subscribers WHERE status = 'active' GROUP BY timing")->fetchAll();
        $out = ['evening' => 0, 'morning' => 0];
        foreach ($rows as $r) {
            $out[$r['timing']] = (int) $r['n'];
        }
        return $out;
    }

    public function recordAttempt(string $ipHash, string $now): void
    {
        $this->pdo->prepare('INSERT INTO signup_attempts (ip_hash, at) VALUES (?, ?)')->execute([$ipHash, $now]);
    }

    public function attemptsSince(string $since, ?string $ipHash = null): int
    {
        if ($ipHash === null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM signup_attempts WHERE at >= ?');
            $stmt->execute([$since]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM signup_attempts WHERE ip_hash = ? AND at >= ?');
            $stmt->execute([$ipHash, $since]);
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete what the privacy notice says is deleted: unconfirmed sign-ups after
     * 7 days, unsubscribed addresses after 30 days, sign-up attempts after a day,
     * and the record of reminders sent after 60 days.
     *
     * @return array{pending: int, unsubscribed: int, attempts: int}
     */
    public function purge(string $now): array
    {
        $t = strtotime($now . ' UTC');
        $weekAgo = gmdate('Y-m-d H:i:s', $t - 7 * 86400);
        $monthAgo = gmdate('Y-m-d H:i:s', $t - 30 * 86400);
        $dayAgo = gmdate('Y-m-d H:i:s', $t - 86400);

        $ids = $this->pdo->prepare(
            "SELECT id, status FROM subscribers WHERE (status = 'pending' AND confirm_sent_at < ?)
                                                OR (status = 'unsubscribed' AND unsubscribed_at < ?)"
        );
        $ids->execute([$weekAgo, $monthAgo]);

        $counts = ['pending' => 0, 'unsubscribed' => 0, 'attempts' => 0];
        foreach ($ids->fetchAll() as $row) {
            $counts[$row['status']]++;
            $this->pdo->prepare('DELETE FROM reminder_sends WHERE subscriber_id = ?')->execute([$row['id']]);
            $this->pdo->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$row['id']]);
        }
        $stmt = $this->pdo->prepare('DELETE FROM signup_attempts WHERE at < ?');
        $stmt->execute([$dayAgo]);
        $counts['attempts'] = $stmt->rowCount();
        $this->pdo->prepare('DELETE FROM reminder_sends WHERE day < ?')->execute([gmdate('Y-m-d', $t - 60 * 86400)]);
        return $counts;
    }

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
