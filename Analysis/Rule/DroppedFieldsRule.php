<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\DebugBar\Analysis\Finding;

/**
 * Business Central sent price band fields the storefront never reads. Often the answer to
 * "BC has a discount, the shop does not show it".
 */
class DroppedFieldsRule extends AbstractRule
{
    public function apply(array $profile): array
    {
        $fields = (array) ($this->summary($profile)['dropped_fields'] ?? []);

        if ($fields === []) {
            return [];
        }

        return [new Finding(
            'c365.dropped_fields',
            Finding::SEVERITY_INFO,
            self::SECTION,
            sprintf(
                'BC sent %d price field(s) the shop drops: %s.',
                count($fields),
                implode(', ', array_keys($fields))
            ),
            'Commerce365 only reads price, minimumQuantity, salesPrice, pricePerUoM and uoM from a band.',
            'If one of these should show, it needs an additional data provider in Commerce365, not a BC change.',
            ['dropped_fields' => $fields],
            $this->action('Review prices')
        )];
    }
}
