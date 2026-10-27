<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Model\PriceDecoder;

class PriceDecoderTest extends TestCase
{
    #[Test]
    public function theJsonInsideTheJsonIsDecoded(): void
    {
        $decoded = (new PriceDecoder())->decode([
            'productId' => '42',
            'type' => 'simple',
            'priceHtml' => '<span class="price">12,40</span><span class="commerce365-price-per-uom">per m2</span>',
            'tierPriceHtml' => '',
            'priceConfig' => json_encode([
                'prices' => ['finalPrice' => ['amount' => 12.4], 'oldPrice' => ['amount' => 14], 'basePrice' => 10.25],
                'tierPrices' => [['qty' => 10, 'price' => 11.9]],
            ]),
            'configurableConfig' => '',
            'tierPricesPerUom' => '{"m2":[{"qty":1,"price":3.1}]}',
        ]);

        $this->assertSame(42, $decoded['product_id']);
        $this->assertSame(12.4, $decoded['final_price']);
        $this->assertSame(14, $decoded['old_price']);
        $this->assertSame(10.25, $decoded['base_price']);
        $this->assertSame([['qty' => 10, 'price' => 11.9]], $decoded['tier_rows']);
        $this->assertSame(['m2' => [['qty' => 1, 'price' => 3.1]]], $decoded['tier_prices_per_uom']);
        $this->assertNull($decoded['option_prices']);
        $this->assertTrue($decoded['has_price_html']);
        $this->assertFalse($decoded['has_tier_price_html']);
        $this->assertTrue($decoded['has_uom_in_html']);
    }

    #[Test]
    public function tierRowsFallBackToThePricesObject(): void
    {
        $decoded = (new PriceDecoder())->decode([
            'priceConfig' => ['prices' => ['tierPrices' => [['qty' => 5]]]],
        ]);

        $this->assertSame([['qty' => 5]], $decoded['tier_rows']);
        $this->assertNull($decoded['product_id']);
    }

    #[Test]
    public function aListItemKeepsTheAmountItsPriceHtmlPrinted(): void
    {
        $decoder = new PriceDecoder();

        $this->assertSame(713.03, $decoder->decode(['priceHtml' => '<span data-price-amount="713.03">x</span>'])['html_amount']);
        // A from-price line prints no amount
        $this->assertNull($decoder->decode(['priceHtml' => 'vanaf 3,76 per m2'])['html_amount']);
    }

    #[Test]
    public function aFieldThatDoesNotDecodeIsKeptAsText(): void
    {
        $this->assertSame(['unparsed' => '{broken'], (new PriceDecoder())->parse('{broken'));
        $this->assertNull((new PriceDecoder())->parse(''));
    }
}
