<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

/**
 * Commerce365 called the Hub or BC while rendering a storefront page. Customer prices
 * belong in the customer-price request: a call here makes the page wait on BC, and a page
 * carrying one customer's price must not be cached for the next.
 */
class CallInPageRenderRule extends AbstractRule
{
    public function apply(array $profile): array
    {
        $calls = $this->calls($profile);

        if ($calls === [] || ($this->request($profile)['area'] ?? '') !== 'frontend') {
            return [];
        }

        $endpoints = array_values(array_unique(array_map(
            static fn (array $call): string => (string) ($call['endpoint'] ?? '?'),
            $calls
        )));

        return [new Finding(
            'c365.call_in_page_render',
            Finding::SEVERITY_ERROR,
            self::SECTION,
            sprintf(
                'The page called Commerce365 %d %s while it rendered: %s.',
                count($calls),
                count($calls) === 1 ? 'time' : 'times',
                implode(', ', $endpoints)
            ),
            'The response waits on Business Central, and per customer data in a page breaks the full page cache.',
            'Find what triggers the call (the timeline shows when) and move it to the AJAX price request.',
            ['call_count' => count($calls), 'endpoints' => $endpoints],
            $this->action('Review the calls')
        )];
    }
}
