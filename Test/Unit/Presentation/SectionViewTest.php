<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Presentation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Presentation\SectionView;

class SectionViewTest extends TestCase
{
    #[Test]
    public function aPageProfileSaysWhereThePricesAre(): void
    {
        $view = (new SectionView())->view(['sections' => []]);

        $this->assertSame(0, $view['count']);
        $this->assertSame(['prices', 'calls', 'config'], array_column($view['tabs'], 'id'));
        $this->assertSame('note', $view['tabs'][0]['blocks'][0]['type']);
        $this->assertStringContainsString('request list', $view['tabs'][0]['blocks'][0]['text']);
    }

    #[Test]
    public function aPriceRequestProfileListsEveryProductWithItsDroppedFields(): void
    {
        $view = (new SectionView())->view(['sections' => ['c365' => [
            'summary' => ['priced_count' => 2, 'count' => 1, 'cache_requested' => 2, 'cache_missed' => 1, 'cache_miss_rate' => 50.0],
            'payload' => [
                'items' => [[
                    'transport' => 'hub', 'method' => 'POST', 'endpoint' => 'price', 'status' => 502,
                    'ok' => false, 'slow' => false, 'duration_ms' => 80.5, 'bytes' => 10, 'request' => [], 'response' => [],
                ]],
                'prices' => [
                    'customer_id' => 12,
                    'page_product_id' => 0,
                    'requested' => [5, 6],
                    'duration_ms' => 90.0,
                    'products' => [
                        ['product_id' => 5, 'type' => 'simple', 'final_price' => 9.5, 'old_price' => null, 'tier_rows' => []],
                        ['product_id' => 6, 'type' => 'simple', 'final_price' => 4, 'old_price' => 5, 'tier_rows' => [[]]],
                    ],
                ],
                'raw' => [[
                    'bands' => [],
                    'diff' => ['products' => [['product_id' => 6, 'bands' => [['used' => ['price'], 'dropped' => ['campaign']]]]]],
                ]],
                'context' => ['customer_id' => 12, 'parent_customer_id' => 10, 'config' => ['use_special_price' => true]],
            ],
        ]]]);

        $this->assertSame(2, $view['count']);

        $table = $view['tabs'][0]['blocks'][1];
        $this->assertSame('table', $table['type']);
        $this->assertSame('cache', $table['rows'][0]['bc']);
        $this->assertSame('', $table['rows'][0]['dropped']);
        $this->assertSame('fetched', $table['rows'][1]['bc']);
        $this->assertSame(['text' => 'campaign', 'tone' => 'warn'], $table['rows'][1]['dropped']);
        $this->assertSame('6', $table['rows'][1]['_target']);

        $calls = $view['tabs'][1];
        $this->assertSame('Calls (1)', $calls['label']);
        $this->assertSame('bad', $calls['blocks'][0]['rows'][0]['_tone']);
        $this->assertSame('502', $calls['blocks'][0]['rows'][0]['status']);

        $config = $view['tabs'][2]['blocks'][0]['items'];
        $this->assertSame('10', $config[1]['value']);
        $this->assertSame(['label' => 'use_special_price', 'value' => 'on', 'mono' => true], $config[2]);
    }
}
