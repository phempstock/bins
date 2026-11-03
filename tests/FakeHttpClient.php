<?php
declare(strict_types=1);

namespace Bins\Tests;

use Bins\Http\HttpClient;

/**
 * Stands in for ReCollect. Each place ID answers with a list of events
 * (as ReCollect returns them), or throws. Every request is recorded.
 */
final class FakeHttpClient implements HttpClient
{
    public array $requests = [];
    /** @var array<string, list<array>|\RuntimeException> place ID => events, or an error to throw */
    public array $places = [];

    public function getJson(string $url, array $params = []): array
    {
        $this->requests[] = [$url, $params];
        if (!preg_match('#/api/places/([0-9A-F-]{36})/services/50003/events$#i', $url, $m)) {
            throw new \RuntimeException("Unexpected GET {$url}");
        }
        $answer = $this->places[$m[1]] ?? new \RuntimeException('HTTP 404 from ' . $url);
        if ($answer instanceof \RuntimeException) {
            throw $answer;
        }
        return ['zones' => [], 'events' => $answer, 'parcel_opts' => []];
    }

    public function postJson(string $url, array $params): array
    {
        throw new \RuntimeException("Unexpected POST {$url}");
    }

    /** One ReCollect event: a day with flags such as 'Residual', 'Recycling', 'Garden'. */
    public static function event(string $day, string ...$flags): array
    {
        return ['day' => $day, 'is_approved' => 1, 'zone_id' => 3724, 'flags' => array_map(
            static fn(string $f) => ['name' => $f, 'subject' => $f, 'event_type' => 'pickup', 'opts' => ['event_type' => 'pickup']],
            $flags
        )];
    }

    /**
     * A Wednesday round like most of The Park: general waste and recycling on
     * alternate Wednesdays from 4 Nov 2026, and garden waste on two Fridays.
     */
    public static function wednesdayRound(): array
    {
        $events = [];
        $day = strtotime('2026-11-04 12:00:00');
        for ($i = 0; $i < 9; $i++) {
            $events[] = self::event(date('Y-m-d', $day), $i % 2 === 0 ? 'Residual' : 'Recycling');
            $day = strtotime('+7 days', $day);
        }
        $events[] = self::event('2026-11-13', 'Garden');
        $events[] = self::event('2026-12-11', 'Garden');
        return $events;
    }
}
