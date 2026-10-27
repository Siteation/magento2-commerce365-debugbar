<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Plugin;

use Closure;
use Commerce365\Core\Service\Customer\GetParentCustomerId;
use Commerce365\CustomerPrice\Api\CustomerPriceInterface;
use Commerce365\CustomerPrice\Model\Config as PriceConfig;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Model\ProfileManager;

/**
 * The customer-price REST request: which products the page asked for, and what came back.
 *
 * Also records the context that decides the answer: the customer, the B2B parent whose
 * prices are used, and the price settings in effect for the store the request named.
 * Read after the call, because CustomerPrice sets the current store while it runs.
 */
class PricePlugin
{
    public function __construct(
        private readonly ProfileManager $manager,
        private readonly Commerce365Collector $collector,
        private readonly GetParentCustomerId $parentCustomerId,
        private readonly PriceConfig $priceConfig
    ) {
    }

    public function aroundGetCustomerPrice(
        CustomerPriceInterface $subject,
        Closure $proceed,
        mixed $productInfo,
        mixed $storeId,
        mixed $customerId,
        mixed $productId
    ): mixed {
        if (!$this->manager->isCollecting()) {
            return $proceed($productInfo, $storeId, $customerId, $productId);
        }

        $startedAt = microtime(true);
        $response = $proceed($productInfo, $storeId, $customerId, $productId);
        $durationMs = (microtime(true) - $startedAt) * 1000;

        $this->manager->quietly(Commerce365Collector::SECTION, function () use (
            $productInfo,
            $storeId,
            $customerId,
            $productId,
            $response,
            $durationMs
        ): void {
            $this->collector->recordPriceRequest(
                is_array($productInfo) ? $productInfo : [],
                $storeId,
                $customerId,
                $productId,
                $response,
                $durationMs
            );
            $this->collector->recordContext([
                'customer_id' => is_numeric($customerId) ? (int) $customerId : null,
                'parent_customer_id' => $customerId ? (int) $this->parentCustomerId->execute($customerId) : null,
                'config' => [
                    'ajax_enabled' => $this->priceConfig->isAjaxEnabled(),
                    'db_caching_enabled' => $this->priceConfig->isCachingEnabled(),
                    'high_level_cache_enabled' => $this->priceConfig->isHighLevelCachingEnabled(),
                    'cache_hours' => $this->priceConfig->getCacheHours(),
                    'use_special_price' => $this->priceConfig->useSpecialPrice(),
                    'show_priceperuom' => $this->priceConfig->showPricePerUom(),
                    'show_priceperuom_tier' => $this->priceConfig->showPricePerUomTier(),
                    'use_minimal_qty' => $this->priceConfig->useMinSalableQty(),
                    'configurable_preselect' => $this->priceConfig->preselectConfigurable(),
                    'hide_prices_guest' => $this->priceConfig->isHidePricesGuest(),
                ],
            ]);
        });

        return $response;
    }
}
