<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Plugin;

use Commerce365\CustomerPrice\Service\GetPriceCollectionForProducts;
use Commerce365\CustomerPrice\Service\Request\GetCustomerPrices;
use Commerce365\CustomerPrice\Service\SyncPrices;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Model\ProfileManager;

/**
 * The price cache, and the raw bands BC sent on a miss.
 *
 * GetPriceCollectionForProducts is asked for every product, SyncPrices only for the ones
 * the cache did not have, so the two together are the hit rate. GetCustomerPrices returns
 * the priceLists before Commerce365 maps them, which is the only place the fields the
 * storefront drops are still there. The collection GetPriceCollectionForProducts returns is the
 * price each product was given, cached or fetched. This replaces the plugin of Siteation_Commerce365Debug.
 */
class PriceCachePlugin
{
    public function __construct(
        private readonly ProfileManager $manager,
        private readonly Commerce365Collector $collector
    ) {
    }

    /**
     * @param array<array-key, mixed> $productIds
     * @return null
     */
    public function beforeExecute(object $subject, mixed $productIds = [], mixed $customerId = null)
    {
        if (!$this->manager->isCollecting() || !is_array($productIds)) {
            return null;
        }

        $ids = array_values($productIds);

        if ($subject instanceof GetPriceCollectionForProducts) {
            $this->manager->quietly(Commerce365Collector::SECTION, fn () => $this->collector->recordCacheLookup($ids));
        } elseif ($subject instanceof SyncPrices) {
            $this->manager->quietly(Commerce365Collector::SECTION, fn () => $this->collector->recordCacheMiss($ids));
        }

        return null;
    }

    public function afterExecute(
        object $subject,
        mixed $result,
        mixed $productIds = [],
        mixed $customerId = null
    ): mixed {
        $collecting = $this->manager->isCollecting();

        if ($subject instanceof GetPriceCollectionForProducts && is_iterable($result) && $collecting) {
            $this->manager->quietly(
                Commerce365Collector::SECTION,
                fn () => $this->collector->recordCachedPrices($result)
            );
        }

        if ($subject instanceof GetCustomerPrices && $collecting) {
            $this->manager->quietly(Commerce365Collector::SECTION, fn () => $this->collector->recordRawPrices(
                is_array($productIds) ? array_values($productIds) : [],
                $result
            ));
        }

        return $result;
    }
}
