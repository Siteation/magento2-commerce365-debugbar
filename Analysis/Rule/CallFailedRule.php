<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

/**
 * A Hub or BC call that got no 2xx answer. Commerce365 builds its client with http_errors
 * off and turns every failure into an empty array, so the shop shows no price and logs
 * nothing.
 */
class CallFailedRule extends AbstractRule
{
    public function apply(array $profile): array
    {
        $failed = array_values(array_filter(
            $this->calls($profile),
            static fn (array $call): bool => ($call['ok'] ?? true) === false
        ));

        if ($failed === []) {
            return [];
        }

        $first = $failed[0];

        return [new Finding(
            'c365.call_failed',
            Finding::SEVERITY_ERROR,
            self::SECTION,
            sprintf(
                '%d Commerce365 %s failed. First: %s answered %s.',
                count($failed),
                count($failed) === 1 ? 'call' : 'calls',
                (string) ($first['endpoint'] ?? '?'),
                $first['status'] === null ? ($first['error'] ?? 'nothing') : (string) $first['status']
            ),
            'Commerce365 hides these: the caller gets an empty result, so a price or document is missing '
                . 'without an error anywhere.',
            'Open the call in the Calls tab, then check the Hub or BC connection settings and var/log/Commerce365.log.',
            ['failed_count' => count($failed), 'statuses' => array_column($failed, 'status')],
            $this->action('Review the calls')
        )];
    }
}
