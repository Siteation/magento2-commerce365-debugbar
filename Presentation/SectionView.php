<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Presentation;

use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Api\SectionViewInterface;

/**
 * The stored half of the Commerce365 section: Prices, Calls and Config.
 *
 * Built from the profile the bar has open. On a page profile that is usually empty,
 * because prices arrive in the customer-price request after the page; the live reader
 * finds that request and loads it, and draws its own tabs from it.
 */
class SectionView implements SectionViewInterface
{
    public function view(array $profile): array
    {
        $section = (array) ($profile['sections'][Commerce365Collector::SECTION] ?? []);
        $summary = (array) ($section['summary'] ?? []);
        $payload = (array) ($section['payload'] ?? []);

        return [
            'count' => (int) ($summary['priced_count'] ?? 0),
            'tabs' => [
                $this->pricesTab($payload, $summary),
                $this->callsTab($payload, $summary),
                $this->configTab($payload),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function pricesTab(array $payload, array $summary): array
    {
        $prices = is_array($payload['prices'] ?? null) ? $payload['prices'] : null;

        if ($prices === null) {
            return ['id' => 'prices', 'label' => 'Prices', 'blocks' => [[
                'type' => 'note',
                'text' => 'No customer-price request in this profile. Prices load after the page, '
                    . 'in their own request: open it from the request list.',
            ]]];
        }

        $bands = $this->bandsByProduct($payload);
        $rows = [];

        foreach ((array) ($prices['products'] ?? []) as $product) {
            $id = (int) ($product['product_id'] ?? 0);
            $dropped = $bands[$id]['dropped'] ?? [];

            $rows[] = [
                'product' => (string) $id,
                'type' => (string) ($product['type'] ?? ''),
                'final' => $this->number($product['final_price'] ?? null),
                'old' => $this->number($product['old_price'] ?? null),
                'tiers' => (string) count((array) ($product['tier_rows'] ?? [])),
                'bc' => isset($bands[$id]) ? 'fetched' : 'cache',
                'dropped' => $dropped === []
                    ? ''
                    : ['text' => implode(', ', $dropped), 'tone' => 'warn'],
                '_target' => (string) $id,
            ];
        }

        $missRate = $summary['cache_miss_rate'] ?? null;

        return ['id' => 'prices', 'label' => 'Prices', 'blocks' => [
            ['type' => 'facts', 'items' => [
                ['label' => 'Customer', 'value' => (string) ($prices['customer_id'] ?? '-'), 'mono' => true],
                [
                    'label' => 'Page',
                    'value' => ($prices['page_product_id'] ?? 0) ? 'Product ' . $prices['page_product_id'] : 'List',
                ],
                ['label' => 'Requested', 'value' => (string) count((array) ($prices['requested'] ?? []))],
                ['label' => 'Priced', 'value' => (string) count($rows)],
                [
                    'label' => 'Cache misses',
                    'value' => $missRate === null
                        ? '-'
                        : sprintf(
                            '%d of %d (%s%%)',
                            $summary['cache_missed'] ?? 0,
                            $summary['cache_requested'] ?? 0,
                            $missRate
                        ),
                    'tone' => (int) ($summary['cache_missed'] ?? 0) > 0 ? 'warn' : 'ok',
                ],
                ['label' => 'Time', 'value' => sprintf('%s ms', round((float) ($prices['duration_ms'] ?? 0), 1))],
            ]],
            [
                'type' => 'table',
                'columns' => [
                    'product',
                    'type',
                    ['key' => 'final', 'label' => 'Final', 'num' => true],
                    ['key' => 'old', 'label' => 'Old', 'num' => true],
                    ['key' => 'tiers', 'label' => 'Tiers', 'num' => true],
                    ['key' => 'bc', 'label' => 'Source'],
                    ['key' => 'dropped', 'label' => 'Dropped BC fields', 'mono' => true],
                ],
                'rows' => $rows,
                'empty' => 'The request priced no products.',
            ],
            ...$this->rawBlocks($payload),
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function rawBlocks(array $payload): array
    {
        $raw = (array) ($payload['raw'] ?? []);

        if ($raw === []) {
            return [[
                'type' => 'note',
                'text' => 'Every price came from the Commerce365 price cache, so BC was not asked. '
                    . 'Flush the price cache and reload to see the raw bands.',
            ]];
        }

        return [
            ['type' => 'heading', 'text' => 'Raw BC price bands'],
            ['type' => 'json', 'value' => array_map(
                static fn (array $capture): array => $capture['bands'] ?? [],
                $raw
            )],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function callsTab(array $payload, array $summary): array
    {
        $rows = [];

        foreach ((array) ($payload['items'] ?? []) as $call) {
            $tone = match (true) {
                ($call['ok'] ?? false) === false => 'bad',
                ($call['slow'] ?? false) === true => 'warn',
                default => null,
            };

            $rows[] = [
                'transport' => (string) ($call['transport'] ?? ''),
                'method' => (string) ($call['method'] ?? ''),
                'endpoint' => (string) ($call['endpoint'] ?? ''),
                'status' => (string) ($call['status'] ?? ($call['error'] ?? 'no response')),
                'ms' => $this->number($call['duration_ms'] ?? null),
                'bytes' => $this->number($call['bytes'] ?? null),
                '_tone' => $tone,
            ];
        }

        $blocks = [
            [
                'type' => 'table',
                'columns' => [
                    'transport',
                    'method',
                    ['key' => 'endpoint', 'label' => 'Endpoint', 'mono' => true],
                    'status',
                    ['key' => 'ms', 'label' => 'ms', 'num' => true],
                    ['key' => 'bytes', 'label' => 'Bytes', 'num' => true],
                ],
                'rows' => $rows,
                'empty' => 'No Hub or BC calls in this request. A cached price needs none.',
            ],
        ];

        if ($rows !== []) {
            $blocks[] = ['type' => 'json', 'value' => array_map(
                static fn (array $call): array => [
                    'endpoint' => $call['endpoint'] ?? null,
                    'request' => $call['request'] ?? null,
                    'response' => $call['response'] ?? null,
                ],
                (array) $payload['items']
            )];
        }

        return [
            'id' => 'calls',
            'label' => sprintf('Calls (%d)', (int) ($summary['count'] ?? count($rows))),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function configTab(array $payload): array
    {
        $context = (array) ($payload['context'] ?? []);

        if ($context === []) {
            return ['id' => 'config', 'label' => 'Config', 'blocks' => [[
                'type' => 'note',
                'text' => 'The settings are read with the customer-price request. Open that request to see them.',
            ]]];
        }

        $items = [
            ['label' => 'Customer', 'value' => (string) ($context['customer_id'] ?? '-'), 'mono' => true],
            ['label' => 'Prices of', 'value' => (string) ($context['parent_customer_id'] ?? '-'), 'mono' => true],
        ];

        foreach ((array) ($context['config'] ?? []) as $key => $value) {
            $items[] = [
                'label' => (string) $key,
                'value' => match (true) {
                    is_bool($value) => $value ? 'on' : 'off',
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value),
                },
                'mono' => true,
            ];
        }

        return ['id' => 'config', 'label' => 'Config', 'blocks' => [['type' => 'facts', 'items' => $items]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{dropped: list<string>}>
     */
    private function bandsByProduct(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['raw'] ?? []) as $capture) {
            foreach ((array) ($capture['diff']['products'] ?? []) as $product) {
                $dropped = [];

                foreach ((array) ($product['bands'] ?? []) as $band) {
                    array_push($dropped, ...(array) ($band['dropped'] ?? []));
                }

                $out[(int) ($product['product_id'] ?? 0)] = ['dropped' => array_values(array_unique($dropped))];
            }
        }

        return $out;
    }

    private function number(mixed $value): string
    {
        return is_int($value) || is_float($value) ? (string) $value : '';
    }
}
