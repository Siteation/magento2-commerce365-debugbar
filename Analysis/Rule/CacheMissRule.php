<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

/**
 * Most of a large price request missed the Commerce365 price cache. One miss is normal,
 * the first visit after the cache hours run out; most of fifty means BC is asked on every
 * visit.
 */
class CacheMissRule extends AbstractRule
{
    public function __construct(
        private readonly int $minProducts = 10,
        private readonly float $maxMissRate = 50.0
    ) {
    }

    public function apply(array $profile): array
    {
        $summary = $this->summary($profile);
        $requested = (int) ($summary['cache_requested'] ?? 0);
        $rate = $summary['cache_miss_rate'] ?? null;

        if ($requested < $this->minProducts || !is_numeric($rate) || (float) $rate < $this->maxMissRate) {
            return [];
        }

        return [new Finding(
            'c365.cache_miss_rate',
            Finding::SEVERITY_WARNING,
            self::SECTION,
            sprintf(
                '%d of %d products missed the price cache (%s%%).',
                (int) ($summary['cache_missed'] ?? 0),
                $requested,
                $rate
            ),
            'Every miss is a Business Central round trip before the prices show.',
            'Reload the page: if it still misses, check db_caching_enabled and cache_hours in the Config tab.',
            ['requested' => $requested, 'missed' => (int) ($summary['cache_missed'] ?? 0), 'miss_rate' => $rate],
            $this->action('Review prices')
        )];
    }
}
