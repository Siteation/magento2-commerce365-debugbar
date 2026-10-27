<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Analysis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Analysis\Rule\CacheMissRule;
use Siteation\Commerce365DebugBar\Analysis\Rule\CallFailedRule;
use Siteation\Commerce365DebugBar\Analysis\Rule\CallInPageRenderRule;
use Siteation\Commerce365DebugBar\Analysis\Rule\DroppedFieldsRule;
use Siteation\Commerce365DebugBar\Analysis\Rule\GuestPricedPageRule;
use Siteation\Commerce365DebugBar\Analysis\Rule\SlowCallRule;
use Siteation\DebugBar\Api\RuleInterface;

class RulesTest extends TestCase
{
    #[Test]
    public function aCallWithoutATwoHundredIsAnError(): void
    {
        $finding = $this->only(new CallFailedRule(), $this->profile('webapi_rest', [
            ['endpoint' => 'price', 'status' => 200, 'ok' => true],
            ['endpoint' => 'price', 'status' => 502, 'ok' => false],
        ]));

        $this->assertSame('c365.call_failed', $finding['id']);
        $this->assertSame('error', $finding['severity']);
        $this->assertStringContainsString('price answered 502', $finding['message']);
        $this->assertSame('c365', $finding['action']['section']);
    }

    #[Test]
    public function aCallThatNeverGotAnAnswerSaysWhatWentWrong(): void
    {
        $finding = $this->only(new CallFailedRule(), $this->profile('webapi_rest', [
            ['endpoint' => 'price', 'status' => null, 'ok' => false, 'error' => 'cURL error 28'],
        ]));

        $this->assertStringContainsString('cURL error 28', $finding['message']);
    }

    #[Test]
    public function aCallIsOnlyAProblemDuringAPageRender(): void
    {
        $calls = [['endpoint' => 'price', 'ok' => true]];

        $this->assertSame([], (new CallInPageRenderRule())->apply($this->profile('webapi_rest', $calls)));
        $this->assertSame('c365.call_in_page_render', $this->only(new CallInPageRenderRule(), $this->profile('frontend', $calls))['id']);
    }

    #[Test]
    public function theWarmUpOnAStoredPageIsInfo(): void
    {
        $profile = $this->profile('frontend', [['endpoint' => 'price', 'ok' => true, 'origin' => 'preload']], [
            'preload_requested' => 12, 'preload_missed' => 11,
        ]);
        $profile['sections']['pagecache'] = ['summary' => ['status' => 'stored']];

        $finding = $this->only(new CallInPageRenderRule(), $profile);

        $this->assertSame('c365.price_cache_warmup', $finding['id']);
        $this->assertSame('info', $finding['severity']);
        $this->assertStringContainsString('Cache warm-up: Commerce365 fetched 11 products', $finding['message']);
    }

    #[Test]
    public function theWarmUpOnAPageThatWasNotStoredIsAnError(): void
    {
        $profile = $this->profile('frontend', [['endpoint' => 'price', 'origin' => 'preload']], ['preload_missed' => 3]);
        $profile['sections']['pagecache'] = ['summary' => ['status' => 'not_cached']];

        $finding = $this->only(new CallInPageRenderRule(), $profile);

        $this->assertSame('c365.call_in_page_render', $finding['id']);
        $this->assertSame('error', $finding['severity']);
        $this->assertSame('pagecache', $finding['action']['section']);
    }

    #[Test]
    public function aCallBesideTheWarmUpIsStillAnError(): void
    {
        $findings = (new CallInPageRenderRule())->apply($this->profile('frontend', [
            ['endpoint' => 'price', 'origin' => 'preload'],
            ['endpoint' => 'customer', 'origin' => null],
        ]));

        $this->assertSame(
            ['error', 'info'],
            array_map(static fn ($finding): string => $finding->toArray()['severity'], $findings)
        );
        $this->assertStringContainsString(': customer.', $findings[0]->toArray()['message']);
    }

    #[Test]
    public function theSlowestCallIsNamed(): void
    {
        $finding = $this->only(new SlowCallRule(), $this->profile('webapi_rest', [
            ['endpoint' => 'customer', 'slow' => true, 'duration_ms' => 1200.0],
            ['endpoint' => 'price', 'slow' => true, 'duration_ms' => 3400.0],
            ['endpoint' => 'x', 'slow' => false, 'duration_ms' => 10.0],
        ], ['slow_threshold_ms' => 1000]));

        $this->assertStringContainsString('2 Commerce365 calls over 1000 ms. Slowest: price, 3400 ms', $finding['message']);
    }

    #[Test]
    public function aHighMissRateOnlyCountsOnALargeRequest(): void
    {
        $rule = new CacheMissRule(10, 50.0);

        $this->assertSame([], $rule->apply($this->profile('webapi_rest', [], [
            'cache_requested' => 3, 'cache_missed' => 3, 'cache_miss_rate' => 100.0,
        ])));
        $this->assertSame([], $rule->apply($this->profile('webapi_rest', [], [
            'cache_requested' => 40, 'cache_missed' => 4, 'cache_miss_rate' => 10.0,
        ])));
        $this->assertStringContainsString('30 of 40 products missed the price cache in this customer-price request', $this->only($rule, $this->profile('webapi_rest', [], [
            'cache_requested' => 40, 'cache_missed' => 30, 'cache_miss_rate' => 75.0, 'cache_scope' => 'price_request',
        ]))['message']);
    }

    #[Test]
    public function aGuestOnACategoryPageIsTold(): void
    {
        $guest = $this->profile('frontend', [], [], ['logged_in' => false], 'catalog_category_view');

        $this->assertSame('info', $this->only(new GuestPricedPageRule(), $guest)['severity']);
        $this->assertSame([], (new GuestPricedPageRule())->apply(
            $this->profile('frontend', [], [], ['logged_in' => true], 'catalog_category_view')
        ));
        $this->assertSame([], (new GuestPricedPageRule())->apply(
            $this->profile('frontend', [], [], ['logged_in' => false], 'cms_index_index')
        ));
    }

    #[Test]
    public function droppedFieldsAreListed(): void
    {
        $finding = $this->only(new DroppedFieldsRule(), $this->profile('webapi_rest', [], [
            'dropped_fields' => ['priceIncVat' => 4, 'discount' => 4],
        ]));

        $this->assertStringContainsString('priceIncVat, discount', $finding['message']);
        $this->assertSame([], (new DroppedFieldsRule())->apply($this->profile('webapi_rest', [])));
    }

    #[Test]
    public function aProfileWithoutTheSectionFindsNothing(): void
    {
        foreach ([new CallFailedRule(), new CallInPageRenderRule(), new SlowCallRule(), new CacheMissRule(),
            new GuestPricedPageRule(), new DroppedFieldsRule()] as $rule) {
            $this->assertSame([], $rule->apply(['sections' => []]), $rule::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function only(RuleInterface $rule, array $profile): array
    {
        $findings = $rule->apply($profile);
        $this->assertCount(1, $findings);

        return $findings[0]->toArray();
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function profile(
        string $area,
        array $calls,
        array $summary = [],
        array $payload = [],
        string $action = 'commerce365_customer_price'
    ): array {
        return ['sections' => [
            'request' => ['summary' => ['area' => $area, 'action' => $action]],
            'c365' => ['summary' => $summary, 'payload' => ['items' => $calls, ...$payload]],
        ]];
    }
}
