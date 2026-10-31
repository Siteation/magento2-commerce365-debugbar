<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Collector;

use Commerce365\CustomerPrice\Model\CachedPrice;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ResponseInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\Commerce365DebugBar\Model\BandDiff;
use Siteation\Commerce365DebugBar\Model\PriceDecoder;
use Siteation\DebugBar\Model\Clock;
use Siteation\DebugBar\Model\Config;
use Siteation\DebugBar\Model\Redactor;

class Commerce365CollectorTest extends TestCase
{
    #[Test]
    public function aCallCarriesTheStatusItsResponseProcessorSaw(): void
    {
        $collector = $this->collector();
        $collector->openCall();
        $collector->noteResponse(200, 512);
        $collector->recordCall('bc_oauth', 'POST', 'GetPrices_GetPricesById', 5.0, 120.0, ['customerId' => 1], ['priceLists' => []]);

        $call = $collector->payload()['items'][0];

        $this->assertSame(200, $call['status']);
        $this->assertSame(512, $call['bytes']);
        $this->assertTrue($call['ok']);
        $this->assertSame('BC (OAuth) POST GetPrices_GetPricesById', $call['name']);
        // start_ms and at_ms make it a span on the debugbar's timeline
        $this->assertSame(5.0, $call['start_ms']);
        $this->assertSame(125.0, $call['at_ms']);
        $this->assertSame(0, $collector->summary()['failed_count']);
    }

    #[Test]
    public function aCallWithoutAnAnswerOrOutsideTwoHundredCountsAsFailed(): void
    {
        $collector = $this->collector();

        $collector->openCall();
        $collector->noteResponse(500, 20);
        $collector->recordCall('hub', 'POST', 'price', 0.0, 10.0, [], []);

        // A status left over from the call before must not be read as this one's
        $collector->openCall();
        $collector->recordCall('hub', 'GET', 'customer', 20.0, 10.0, [], []);

        $items = $collector->payload()['items'];

        $this->assertFalse($items[0]['ok']);
        $this->assertNull($items[1]['status']);
        $this->assertFalse($items[1]['ok']);
        $this->assertSame(2, $collector->summary()['failed_count']);
    }

    #[Test]
    public function credentialsNeverReachTheProfile(): void
    {
        $collector = $this->collector();
        $collector->recordCall('bc_basic', 'POST', 'x', 0.0, 1.0, [
            'auth' => ['user', 'pass'],
            'headers' => ['secretKey' => 's'],
            'customerId' => 3,
        ], []);

        $payload = $collector->payload();

        $this->assertSame(['customerId' => 3], $payload['bodies'][0]['request']);
        $this->assertArrayNotHasKey('request', $payload['items'][0]);
    }

    #[Test]
    public function aSlowCallIsMarkedAndCounted(): void
    {
        $collector = $this->collector(slowCallMs: 100.0);
        $collector->noteResponse(200, 1);
        $collector->recordCall('hub', 'POST', 'price', 0.0, 150.0, [], []);

        $this->assertTrue($collector->payload()['items'][0]['slow']);
        $this->assertSame(1, $collector->summary()['slow_count']);
    }

    #[Test]
    public function theCacheMissRateComesFromLookupsAndMisses(): void
    {
        $collector = $this->collector();
        $collector->recordCacheLookup([1, 2, 3, 4]);
        $collector->recordCacheMiss([3]);

        $summary = $collector->summary();

        $this->assertSame(4, $summary['cache_requested']);
        $this->assertSame(1, $summary['cache_missed']);
        $this->assertSame(25.0, $summary['cache_miss_rate']);
    }

    #[Test]
    public function aBandSixLevelsIntoAHubAnswerKeepsItsValues(): void
    {
        $collector = $this->collector();
        $collector->recordCall('hub', 'POST', 'price', 0.0, 1.0, [], [
            'priceLists' => [['productId' => 263, 'prices' => [['price' => 63.41391, 'minimumQuantity' => 1]]]],
        ]);

        $response = $collector->payload()['bodies'][0]['response'];

        $this->assertSame(63.41391, $response['priceLists'][0]['prices'][0]['price']);
    }

    #[Test]
    public function theWarmUpKeepsItsCallsAndLookupsApart(): void
    {
        $collector = $this->collector();
        $collector->during(Commerce365Collector::ORIGIN_PRELOAD, function () use ($collector): void {
            $collector->recordCacheLookup(range(1, 12));
            $collector->recordCacheMiss(range(1, 11));
            $collector->recordCall('hub', 'POST', 'price', 0.0, 115.0, [], []);
        });
        $collector->recordCacheLookup([20]);

        $summary = $collector->summary();

        $this->assertSame('preload', $collector->payload()['items'][0]['origin']);
        $this->assertSame(1, $summary['preload_call_count']);
        $this->assertSame(12, $summary['preload_requested']);
        $this->assertSame(11, $summary['preload_missed']);
        // The rate is the lookups outside the warm-up, here the one on the page itself
        $this->assertSame(1, $summary['cache_requested']);
        $this->assertSame(0.0, $summary['cache_miss_rate']);
        $this->assertSame('page', $summary['cache_scope']);
    }

    #[Test]
    public function theCachedPriceRowsAreKeptPerProduct(): void
    {
        $collector = $this->collector();
        $collector->recordCachedPrices([
            new CachedPrice(63.41391, 263, 63.41391, [['qty' => 55, 'price' => 52.22043, 'additional' => []]]),
            'not a row',
        ]);

        $this->assertSame(
            [263 => ['price' => 63.41391, 'special_price' => 63.41391, 'tiers' => [['qty' => 55, 'price' => 52.22043]]]],
            $collector->payload()['cached']
        );
    }

    #[Test]
    public function rawBandsKeepTheirValuesUnderTheFullPolicy(): void
    {
        $collector = $this->collector();
        $collector->recordRawPrices([7], [['productId' => 7, 'prices' => [['price' => 10, 'campaign' => 'X']]]]);

        $raw = $collector->payload()['raw'][0];

        $this->assertSame([['price' => 10, 'campaign' => 'X']], $raw['bands'][0]['prices']);
        $this->assertSame(['campaign' => 1], $collector->summary()['dropped_fields']);
    }

    #[Test]
    public function rawBandsKeepOnlyKeysAndTypesUnderAnyOtherPolicy(): void
    {
        $collector = $this->collector(policy: Redactor::POLICY_MASKED);
        $collector->recordRawPrices([7], [['productId' => 7, 'prices' => [['price' => 10.5, 'campaign' => 'X']]]]);

        $this->assertSame(
            [['price' => 'float', 'campaign' => 'string']],
            $collector->payload()['raw'][0]['bands'][0]['prices']
        );
    }

    #[Test]
    public function thePriceRequestIsDecodedPerProduct(): void
    {
        $collector = $this->collector();
        $collector->recordPriceRequest(['5', '6'], '1', '12', 0, [
            ['productId' => 5, 'type' => 'simple', 'priceConfig' => '{"prices":{"finalPrice":{"amount":9.5}}}'],
            ['productId' => 6, 'type' => 'simple', 'priceConfig' => ''],
        ], 33.3);

        $prices = $collector->payload()['prices'];

        $this->assertSame([5, 6], $prices['requested']);
        $this->assertSame(12, $prices['customer_id']);
        $this->assertSame(0, $prices['page_product_id']);
        $this->assertSame(9.5, $prices['products'][0]['final_price']);
        $this->assertSame(2, $collector->summary()['priced_count']);
    }

    #[Test]
    public function theEndOfTheRequestRecordsWhetherACustomerWasSignedIn(): void
    {
        $context = $this->createStub(HttpContext::class);
        $context->method('getValue')->willReturn(false);
        $collector = $this->collector(httpContext: $context);

        $this->assertNull($collector->payload()['logged_in']);
        $collector->finalize($this->createStub(ResponseInterface::class));
        $this->assertFalse($collector->payload()['logged_in']);
    }

    #[Test]
    public function resetForgetsTheLastRequest(): void
    {
        $collector = $this->collector();
        $collector->recordCacheLookup([1]);
        $collector->recordRawPrices([1], []);
        $collector->recordContext(['customer_id' => 1]);
        $collector->reset();

        $payload = $collector->payload();

        $this->assertSame([], $payload['raw']);
        $this->assertSame([], $payload['context']);
        $this->assertNull($payload['prices']);
        $this->assertSame(0, $collector->summary()['cache_requested']);
    }

    #[Test]
    public function aFailedCallKeepsItsMessageOnlyUnderFull(): void
    {
        $failure = new \RuntimeException('cURL error 6 for https://hub.example/api/customer?customerNo=C0042');

        foreach ([
            Redactor::POLICY_FULL => $failure->getMessage(),
            Redactor::POLICY_MASKED => \RuntimeException::class,
            Redactor::POLICY_NONE => \RuntimeException::class,
        ] as $policy => $expected) {
            $collector = $this->collector($policy);
            $collector->recordCall('hub', 'GET', 'customer', 0.0, 1.0, [], null, $failure);

            $call = $collector->payload()['items'][0];
            $this->assertSame($expected, $call['error'], $policy);
            $this->assertFalse($call['ok'], $policy);
        }
    }

    #[Test]
    public function aResponseThatIsNotAnArrayFollowsThePolicy(): void
    {
        foreach ([
            Redactor::POLICY_FULL => 'raw body',
            Redactor::POLICY_MASKED => 'string',
            Redactor::POLICY_NONE => null,
        ] as $policy => $expected) {
            $collector = $this->collector($policy);
            $collector->recordCall('hub', 'POST', 'price', 0.0, 1.0, [], 'raw body');

            $this->assertSame($expected, $collector->payload()['bodies'][0]['response'], $policy);
        }
    }

    private function collector(
        string $policy = Redactor::POLICY_FULL,
        float $slowCallMs = 1000.0,
        ?HttpContext $httpContext = null
    ): Commerce365Collector
    {
        $config = $this->createStub(Config::class);
        $config->method('valuePolicy')->willReturn($policy);

        return new Commerce365Collector(
            new Redactor(),
            new Clock(),
            $config,
            new PriceDecoder(),
            new BandDiff(),
            $httpContext ?? $this->createStub(HttpContext::class),
            new Redactor(maxDepth: 10),
            $slowCallMs
        );
    }
}
