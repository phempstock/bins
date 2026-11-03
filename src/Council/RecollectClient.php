<?php
declare(strict_types=1);

namespace Bins\Council;

use Bins\Http\HttpClient;
use RuntimeException;

/**
 * Reads collection calendars from ReCollect, the service behind Nottingham City
 * Council's "Check my bin collection day" page (nottinghamcity.gov.uk/binreminders).
 *
 *   GET {base}/api/places/{place_id}/services/{service_id}/events
 *       ?nomerge=1&hide=reminder_only&after=YYYY-MM-DD&before=YYYY-MM-DD&locale=en-GB
 *
 * Each event is one day with one or more "flags" (Residual, Recycling, Garden, ...).
 * Bank-holiday changes are already applied by the council (e.g. Christmas Eve
 * collections moved to 23 December), which is the point of using this source.
 */
final class RecollectClient
{
    /** ReCollect flag name => our bin name. */
    public const BINS = [
        'Residual'  => 'waste',
        'Recycling' => 'recycling',
        'Garden'    => 'garden',
    ];

    /** @var list<string> flag names seen that we don't recognise */
    public array $unknownFlags = [];

    public function __construct(
        private HttpClient $http,
        private string $baseUrl = 'https://api.eu.recollect.net',
        private int $serviceId = 50003
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Collections at one address between two UK dates (inclusive).
     *
     * @return array<string, list<string>> 'Y-m-d' => bins, e.g. ['2026-11-04' => ['waste']]
     */
    public function collections(string $placeId, string $from, string $to): array
    {
        if (!preg_match('/^[0-9A-F-]{36}$/i', $placeId)) {
            throw new RuntimeException("Not a ReCollect place ID: {$placeId}");
        }
        $data = $this->http->getJson(
            "{$this->baseUrl}/api/places/{$placeId}/services/{$this->serviceId}/events",
            ['nomerge' => 1, 'hide' => 'reminder_only', 'after' => $from, 'before' => $to, 'locale' => 'en-GB']
        );
        if (!isset($data['events']) || !is_array($data['events'])) {
            throw new RuntimeException('Unexpected response from ReCollect (no events list)');
        }

        $days = [];
        foreach ($data['events'] as $event) {
            $day = (string) ($event['day'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < $from || $day > $to) {
                continue;
            }
            foreach ($event['flags'] ?? [] as $flag) {
                $type = $flag['event_type'] ?? ($flag['opts']['event_type'] ?? 'pickup');
                if ($type !== 'pickup') {
                    continue;
                }
                $bin = self::binFor((string) ($flag['name'] ?? ''));
                if ($bin === null) {
                    $this->unknownFlags[] = (string) ($flag['name'] ?? '?');
                    continue;
                }
                $days[$day][] = $bin;
            }
        }
        ksort($days);
        foreach ($days as &$bins) {
            $bins = array_values(array_unique($bins));
        }
        return $days;
    }

    public static function binFor(string $flagName): ?string
    {
        if (isset(self::BINS[$flagName])) {
            return self::BINS[$flagName];
        }
        return stripos($flagName, 'food') === 0 ? 'food' : null;
    }
}
