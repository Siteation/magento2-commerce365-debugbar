<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

/**
 * A logged out visitor on a page that lists prices. Commerce365 prices are per customer,
 * so a guest never gets one: worth saying before someone files it as a bug.
 */
class GuestPricedPageRule extends AbstractRule
{
    /**
     * @param list<string> $pricedActions full action names of pages that show prices
     */
    public function __construct(
        private readonly array $pricedActions = [
            'catalog_category_view',
            'catalog_product_view',
            'catalogsearch_result_index',
        ]
    ) {
    }

    public function apply(array $profile): array
    {
        $request = $this->request($profile);

        if (($request['area'] ?? '') !== 'frontend'
            || !in_array($request['action'] ?? '', $this->pricedActions, true)
            || ($this->payload($profile)['logged_in'] ?? null) !== false
        ) {
            return [];
        }

        return [new Finding(
            'c365.guest_on_priced_page',
            Finding::SEVERITY_INFO,
            self::SECTION,
            'Logged out: this page shows no Business Central prices.',
            'Commerce365 prices belong to a customer, so a guest gets none, or the catalog price.',
            'Log in as a customer linked to BC to see their prices.',
            ['action' => $request['action']],
            $this->action('Open Commerce365')
        )];
    }
}
