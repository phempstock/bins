<?php
declare(strict_types=1);

namespace Bins\Collections;

/** Names and descriptions of the bins, as residents know them. */
final class Bins
{
    private const ORDER = ['waste', 'recycling', 'garden', 'food'];

    /** Short name, e.g. "general waste". */
    public static function label(string $bin): string
    {
        return match ($bin) {
            'waste' => 'general waste',
            'recycling' => 'recycling',
            'garden' => 'garden waste',
            'food' => 'food waste',
            default => $bin,
        };
    }

    /** The bin itself, e.g. "green general waste bin" (wording from the council's calendar). */
    public static function describe(string $bin): string
    {
        return match ($bin) {
            'waste' => 'green general waste bin',
            'recycling' => 'grey-lidded recycling bin',
            'garden' => 'garden waste bin',
            'food' => 'food waste caddy',
            default => $bin . ' bin',
        };
    }

    /** Colour used for the bin's dot on the site and in emails. */
    public static function colour(string $bin): string
    {
        return match ($bin) {
            'waste' => '#2e7d32',
            'recycling' => '#8a9399',
            'garden' => '#8d6e3f',
            'food' => '#6d4c41',
            default => '#56635e',
        };
    }

    /** "general waste and garden waste" */
    public static function labels(array $bins): string
    {
        $names = array_map([self::class, 'label'], self::sort($bins));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** @return list<string> in a fixed order: waste, recycling, garden, food */
    public static function sort(array $bins): array
    {
        $bins = array_values(array_unique($bins));
        usort($bins, static function (string $a, string $b): int {
            $ia = array_search($a, self::ORDER, true);
            $ib = array_search($b, self::ORDER, true);
            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib) ?: strcmp($a, $b);
        });
        return $bins;
    }
}
