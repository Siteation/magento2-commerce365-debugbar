<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

class SlowCallRule extends AbstractRule
{
    public function apply(array $profile): array
    {
        $slow = array_values(array_filter(
            $this->calls($profile),
            static fn (array $call): bool => ($call['slow'] ?? false) === true
        ));

        if ($slow === []) {
            return [];
        }

        usort($slow, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);
        $threshold = (float) ($this->summary($profile)['slow_threshold_ms'] ?? 1000);

        return [new Finding(
            'c365.call_slow',
            Finding::SEVERITY_WARNING,
            self::SECTION,
            sprintf(
                '%d Commerce365 %s over %s ms. Slowest: %s, %s ms.',
                count($slow),
                count($slow) === 1 ? 'call' : 'calls',
                round($threshold),
                (string) ($slow[0]['endpoint'] ?? '?'),
                round((float) $slow[0]['duration_ms'])
            ),
            'Every price on the page waits for this call when the price cache misses.',
            'Check the timeline for where it sits, and the cache miss count: a warm cache makes no call.',
            ['slow_count' => count($slow), 'threshold_ms' => $threshold],
            $this->action('Review slow calls')
        )];
    }
}
