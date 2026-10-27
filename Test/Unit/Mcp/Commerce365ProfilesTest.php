<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Mcp\Commerce365Profiles;
use Siteation\DebugBar\Model\ProfileStore;

class Commerce365ProfilesTest extends TestCase
{
    #[Test]
    public function withoutAnIdTheNewestPriceRequestIsRead(): void
    {
        $result = $this->profiles([$this->page('page'), $this->priceRequest('prices'), $this->priceRequest('older')])
            ->prices('', null);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('prices', $result['profile_id']);
        $this->assertSame(181, $result['customer_id']);

        $product = $result['products'][0];
        $this->assertSame('bc', $product['source']);
        $this->assertSame(2500.512, $product['bc_base_price']);
        $this->assertSame(['priceIncVat'], $product['dropped_fields']);
        $this->assertSame('price_cache', $result['products'][1]['source']);
    }

    #[Test]
    public function oneProductCanBeAskedFor(): void
    {
        $result = $this->profiles([$this->priceRequest('prices')])->prices('', 8);

        $this->assertSame([8], array_column($result['products'], 'product_id'));
    }

    #[Test]
    public function callsLeaveTheBodiesOutUnlessAsked(): void
    {
        $profiles = $this->profiles([$this->page('page'), $this->priceRequest('prices')]);

        $without = $profiles->calls('', false);
        $with = $profiles->calls('', true);

        $this->assertSame('prices', $without['profile_id']);
        $this->assertArrayNotHasKey('request', $without['calls'][0]);
        $this->assertSame(['CustomerId' => 181], $with['calls'][0]['request']);
        $this->assertSame(1, $without['summary']['count']);
    }

    #[Test]
    public function anUnknownIdAndAnEmptyHistoryAreErrors(): void
    {
        $this->assertSame('error', $this->profiles([])->prices('missing', null)['status']);
        $this->assertSame('error', $this->profiles([$this->page('page')])->calls('', false)['status']);
    }

    /**
     * @param list<array<string, mixed>> $recent newest first
     */
    private function profiles(array $recent): Commerce365Profiles
    {
        $byId = array_column($recent, null, 'id');
        $store = $this->createStub(ProfileStore::class);
        $store->method('recent')->willReturn($recent);
        $store->method('get')->willReturnCallback(static fn (string $id): ?array => $byId[$id] ?? null);

        return new Commerce365Profiles($store);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $id): array
    {
        return ['id' => $id, 'sections' => ['c365' => ['summary' => ['count' => 0], 'payload' => ['items' => [], 'prices' => null]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceRequest(string $id): array
    {
        return ['id' => $id, 'findings' => [['section' => 'c365', 'id' => 'c365.dropped_fields'], ['section' => 'queries']], 'sections' => [
            'request' => ['summary' => ['method' => 'POST', 'path' => '/rest/default_nl/V1/customer-price', 'area' => 'webapi_rest']],
            'c365' => [
                'summary' => ['count' => 1, 'cache_requested' => 2, 'cache_missed' => 1, 'cache_miss_rate' => 50.0],
                'payload' => [
                    'items' => [['endpoint' => 'price', 'status' => 200, 'request' => ['CustomerId' => 181], 'response' => []]],
                    'prices' => [
                        'customer_id' => 181,
                        'requested' => [2, 8],
                        'products' => [['product_id' => 2, 'final_price' => 2500.51], ['product_id' => 8, 'html_amount' => 713.03]],
                    ],
                    'raw' => [[
                        'bands' => [['product_id' => 2, 'prices' => [['minimumQuantity' => 1, 'price' => 2500.512, 'priceIncVat' => 3025.6]]]],
                        'diff' => ['products' => [['product_id' => 2, 'bands' => [['used' => ['price'], 'dropped' => ['priceIncVat']]]]]],
                    ]],
                    'context' => ['customer_id' => 181, 'parent_customer_id' => 181],
                ],
            ],
        ]];
    }
}
