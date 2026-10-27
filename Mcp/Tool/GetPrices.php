<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Mcp\Tool;

use Siteation\Commerce365DebugBar\Mcp\Commerce365Profiles;
use Siteation\DebugBar\Api\McpToolInterface;

class GetPrices implements McpToolInterface
{
    public function __construct(private readonly Commerce365Profiles $profiles)
    {
    }

    public function name(): string
    {
        return 'get_c365_prices';
    }

    public function description(): string
    {
        return 'Explain the customer prices one customer-price request answered: per product the decoded '
            . 'final and old price, tiers, the raw Business Central price bands when BC was asked, and the '
            . 'BC fields the storefront drops; plus the customer, the B2B parent whose prices apply, the price '
            . 'settings and the price cache hit rate. Without a profile id, reads the newest price request.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile_id' => [
                    'type' => 'string',
                    'description' => 'Profile id of a customer-price request. Optional.',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Only this product (entity id). Optional.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        return $this->profiles->prices(
            (string) ($arguments['profile_id'] ?? ''),
            isset($arguments['product_id']) ? (int) $arguments['product_id'] : null
        );
    }
}
