<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Model;

/**
 * Which fields of each Business Central price band the storefront uses, and which it drops.
 *
 * PriceDataBuilder reads price, minimumQuantity and salesPrice, and the additional data
 * providers read pricePerUoM and uoM. Anything else BC sends per band never reaches the
 * shop, which is often the answer to "BC has it, the shop does not show it". Ported from
 * the inspector's rawDiff() in src/lib/parse.js.
 */
class BandDiff
{
    /** @var list<string> */
    public const USED_FIELDS = ['price', 'minimumQuantity', 'salesPrice', 'pricePerUoM', 'uoM'];

    /**
     * @param mixed $priceLists what GetCustomerPrices returned: one entry per product, each with `prices`
     * @return array{
     *     products: list<array{product_id: int|null, bands: list<array{used: list<string>, dropped: list<string>}>}>,
     *     dropped_fields: array<string, int>,
     *     used_fields: list<string>
     * }
     */
    public function diff(mixed $priceLists): array
    {
        $out = ['products' => [], 'dropped_fields' => [], 'used_fields' => self::USED_FIELDS];

        if (!is_array($priceLists)) {
            return $out;
        }

        foreach ($priceLists as $priceList) {
            if (!is_array($priceList)) {
                continue;
            }

            $productId = $priceList['productId'] ?? $priceList['ProductId'] ?? null;
            $product = ['product_id' => is_numeric($productId) ? (int) $productId : null, 'bands' => []];

            foreach (is_array($priceList['prices'] ?? null) ? $priceList['prices'] : [] as $band) {
                $keys = is_array($band) ? array_map('strval', array_keys($band)) : [];
                $used = array_values(array_intersect($keys, self::USED_FIELDS));
                $dropped = array_values(array_diff($keys, self::USED_FIELDS));

                foreach ($dropped as $field) {
                    $out['dropped_fields'][$field] = ($out['dropped_fields'][$field] ?? 0) + 1;
                }

                $product['bands'][] = ['used' => $used, 'dropped' => $dropped];
            }

            $out['products'][] = $product;
        }

        return $out;
    }

    /**
     * The unit price BC sent, as PriceDataBuilder picks it: the band for a quantity of one,
     * or else the first tier. Null when the bands hold no number, which is also what they
     * hold under a value policy other than full.
     *
     * @param array<array-key, mixed> $bands
     */
    public static function basePrice(array $bands): float|int|null
    {
        $first = null;

        foreach ($bands as $band) {
            $price = is_array($band) ? ($band['price'] ?? null) : null;

            if (!is_int($price) && !is_float($price)) {
                continue;
            }

            if ((float) ($band['minimumQuantity'] ?? 0) <= 1) {
                return $price;
            }

            $first ??= $price;
        }

        return $first;
    }
}
