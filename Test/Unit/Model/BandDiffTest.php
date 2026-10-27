<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Test\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siteation\Commerce365DebugBar\Model\BandDiff;

class BandDiffTest extends TestCase
{
    #[Test]
    public function fieldsTheStorefrontDoesNotReadAreCountedAsDropped(): void
    {
        $diff = (new BandDiff())->diff([
            ['productId' => 7, 'prices' => [
                ['price' => 10, 'minimumQuantity' => 1, 'discountPct' => 5, 'campaign' => 'X'],
                ['price' => 9, 'minimumQuantity' => 10, 'discountPct' => 10],
            ]],
            ['ProductId' => '8', 'prices' => [['price' => 3, 'uoM' => 'M2', 'pricePerUoM' => 1.5]]],
        ]);

        $this->assertSame(['discountPct' => 2, 'campaign' => 1], $diff['dropped_fields']);
        $this->assertSame(7, $diff['products'][0]['product_id']);
        $this->assertSame(['price', 'minimumQuantity'], $diff['products'][0]['bands'][0]['used']);
        $this->assertSame(['discountPct', 'campaign'], $diff['products'][0]['bands'][0]['dropped']);
        $this->assertSame(8, $diff['products'][1]['product_id']);
        $this->assertSame([], $diff['products'][1]['bands'][0]['dropped']);
    }

    #[Test]
    public function theBasePriceIsTheBandForAQuantityOfOneElseTheFirstTier(): void
    {
        $this->assertSame(10, BandDiff::basePrice([['price' => 9, 'minimumQuantity' => 10], ['price' => 10, 'minimumQuantity' => 1]]));
        $this->assertSame(13.6, BandDiff::basePrice([['price' => 13.6, 'minimumQuantity' => 5], ['price' => 11.2, 'minimumQuantity' => 20]]));
        // Under a masked value policy the bands hold type names, not numbers
        $this->assertNull(BandDiff::basePrice([['price' => 'float', 'minimumQuantity' => 'int']]));
    }

    #[Test]
    public function somethingThatIsNotAListOfPriceListsGivesAnEmptyDiff(): void
    {
        $diff = (new BandDiff())->diff('nope');

        $this->assertSame([], $diff['products']);
        $this->assertSame(BandDiff::USED_FIELDS, $diff['used_fields']);
    }
}
