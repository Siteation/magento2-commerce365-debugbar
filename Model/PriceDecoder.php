<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Model;

/**
 * Decodes one product entry of the /V1/customer-price response.
 *
 * Commerce365 sends three of its fields as JSON inside the JSON: priceConfig,
 * configurableConfig and tierPricesPerUom. Decoded once here, at record time, so the bar,
 * the findings and MCP all read the same numbers. Ported from the inspector's
 * decodeProductInfo() in src/lib/parse.js.
 */
class PriceDecoder
{
    /**
     * @param array<string, mixed> $entry one item of the response array
     * @return array<string, mixed>
     */
    public function decode(array $entry): array
    {
        $priceConfig = $this->parse($entry['priceConfig'] ?? null);
        $configurableConfig = $this->parse($entry['configurableConfig'] ?? null);
        $prices = is_array($priceConfig['prices'] ?? null) ? $priceConfig['prices'] : null;

        $tierRows = match (true) {
            is_array($priceConfig['tierPrices'] ?? null) && $priceConfig['tierPrices'] !== []
                => $priceConfig['tierPrices'],
            is_array($prices['tierPrices'] ?? null) => $prices['tierPrices'],
            default => [],
        };

        return [
            'product_id' => isset($entry['productId']) ? (int) $entry['productId'] : null,
            'type' => is_string($entry['type'] ?? null) ? $entry['type'] : '',
            'final_price' => $this->amount($prices['finalPrice'] ?? null),
            'old_price' => $this->amount($prices['oldPrice'] ?? null),
            'base_price' => $this->amount($prices['basePrice'] ?? null),
            'tier_rows' => array_values($tierRows),
            'option_prices' => $configurableConfig['optionPrices'] ?? null,
            'tier_prices_per_uom' => $this->parse($entry['tierPricesPerUom'] ?? null),
            'has_price_html' => $this->has($entry['priceHtml'] ?? null),
            'has_tier_price_html' => $this->has($entry['tierPriceHtml'] ?? null),
            'has_uom_in_html' => is_string($entry['priceHtml'] ?? null)
                && str_contains($entry['priceHtml'], 'commerce365-price-per-uom'),
        ];
    }

    /**
     * A field that does not decode is kept as text under one key, so the bar shows what
     * arrived instead of an empty cell that looks like nothing was sent.
     *
     * @return array<mixed>|null
     */
    public function parse(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return ['unparsed' => (string) json_encode($value)];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['unparsed' => $value];
    }

    /**
     * Magento's price config carries an amount either bare or as {amount: n}.
     */
    private function amount(mixed $price): float|int|null
    {
        if (is_array($price)) {
            $price = $price['amount'] ?? null;
        }

        return is_int($price) || is_float($price) ? $price : (is_numeric($price) ? (float) $price : null);
    }

    private function has(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            default => true,
        };
    }
}
