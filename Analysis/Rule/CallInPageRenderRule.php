<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Analysis\Finding;

/**
 * Commerce365 called the Hub or BC while rendering a storefront page. Customer prices
 * belong in the customer-price request: a call here makes the page wait on BC.
 *
 * Commerce365's own price cache warm-up is the exception. It runs while a product list
 * renders for a signed-in customer, puts nothing customer specific in the HTML, and is
 * why the customer-price request after it needs no BC call. It is only a problem when the
 * page was not stored, because then every visit pays for it.
 */
class CallInPageRenderRule extends AbstractRule
{
    public function apply(array $profile): array
    {
        $calls = $this->calls($profile);

        if ($calls === [] || ($this->request($profile)['area'] ?? '') !== 'frontend') {
            return [];
        }

        $preload = array_values(array_filter(
            $calls,
            static fn (array $call): bool => ($call['origin'] ?? null) === Commerce365Collector::ORIGIN_PRELOAD
        ));
        $other = array_values(array_filter(
            $calls,
            static fn (array $call): bool => ($call['origin'] ?? null) !== Commerce365Collector::ORIGIN_PRELOAD
        ));

        $findings = [];

        if ($other !== []) {
            $endpoints = $this->endpoints($other);
            $findings[] = new Finding(
                'c365.call_in_page_render',
                Finding::SEVERITY_ERROR,
                self::SECTION,
                sprintf(
                    'The page called Commerce365 %d %s while it rendered: %s.',
                    count($other),
                    count($other) === 1 ? 'time' : 'times',
                    implode(', ', $endpoints)
                ),
                'The response waits on Business Central, and per customer data in a page breaks the full page cache.',
                'Find what triggers the call (the timeline shows when) and move it to the AJAX price request.',
                ['call_count' => count($other), 'endpoints' => $endpoints],
                $this->action('Review the calls')
            );
        }

        if ($preload !== []) {
            $findings[] = $this->preloadFinding($profile, $preload);
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $calls
     */
    private function preloadFinding(array $profile, array $calls): Finding
    {
        $summary = $this->summary($profile);
        $warmed = (int) ($summary['preload_missed'] ?? 0);
        $status = (string) ($profile['sections']['pagecache']['summary']['status'] ?? '');
        $evidence = [
            'call_count' => count($calls),
            'endpoints' => $this->endpoints($calls),
            'warmed_products' => $warmed,
            'listed_products' => (int) ($summary['preload_requested'] ?? 0),
            'page_cache_status' => $status !== '' ? $status : null,
        ];

        // Not stored: the warm-up runs again on the next visit, and the one after
        if ($status === 'not_cached') {
            return new Finding(
                'c365.call_in_page_render',
                Finding::SEVERITY_ERROR,
                self::SECTION,
                sprintf(
                    'Commerce365 warmed the price cache for %d %s during the render, and the page was not stored.',
                    $warmed,
                    $warmed === 1 ? 'product' : 'products'
                ),
                'The warm-up is meant to run once per page cache miss. On a page that is never stored, every visit '
                    . 'waits on Business Central.',
                'Open the Page cache section for why the page was not stored.',
                $evidence,
                ['label' => 'Review the page cache', 'section' => 'pagecache']
            );
        }

        return new Finding(
            'c365.price_cache_warmup',
            Finding::SEVERITY_INFO,
            self::SECTION,
            sprintf(
                'Cache warm-up: Commerce365 fetched %d %s from BC while the list rendered, for the price request.',
                $warmed,
                $warmed === 1 ? 'product' : 'products'
            ),
            'ProductListPricesPreloadToCache fills the price cache on a page cache miss, so the customer-price '
                . 'request after the page answers from the cache. Nothing customer specific is in the HTML.',
            'Nothing to do. Check the customer-price request: it should show no misses.',
            $evidence,
            $this->action('Review the calls')
        );
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @return list<string>
     */
    private function endpoints(array $calls): array
    {
        return array_values(array_unique(array_map(
            static fn (array $call): string => (string) ($call['endpoint'] ?? '?'),
            $calls
        )));
    }
}
