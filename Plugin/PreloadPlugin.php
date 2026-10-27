<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Plugin;

use Closure;
use Commerce365\CustomerPrice\Plugin\Frontend\ProductListPricesPreloadToCache;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Model\ProfileManager;

/**
 * Marks what Commerce365's own price cache warm-up does while a product list renders.
 *
 * ProductListPricesPreloadToCache asks the price cache for the whole list, so the misses
 * reach BC during the render instead of in the customer-price request after it. That is
 * by design, and kept: without the mark it reads as a BC call in a page render, and its
 * misses as the cache failing the customer.
 */
class PreloadPlugin
{
    public function __construct(
        private readonly ProfileManager $manager,
        private readonly Commerce365Collector $collector
    ) {
    }

    public function aroundAfterGetLoadedProductCollection(
        ProductListPricesPreloadToCache $subject,
        Closure $proceed,
        mixed ...$arguments
    ): mixed {
        if (!$this->manager->isCollecting()) {
            return $proceed(...$arguments);
        }

        return $this->collector->during(
            Commerce365Collector::ORIGIN_PRELOAD,
            static fn (): mixed => $proceed(...$arguments)
        );
    }
}
