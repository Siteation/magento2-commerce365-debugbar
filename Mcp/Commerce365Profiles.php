<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Mcp;

use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\Commerce365DebugBar\Model\BandDiff;
use Siteation\DebugBar\Model\ProfileStore;

/**
 * Finds the profile a Commerce365 question is about, and shapes the answer for an agent.
 *
 * The profile id is optional on both tools. Prices arrive in their own request after the
 * page, so "the last price request" is what an agent asking about prices nearly always
 * means, and making it look that up first is a round trip for nothing.
 */
class Commerce365Profiles
{
    private const RESPONSE_VERSION = 1;

    public function __construct(
        private readonly ProfileStore $store,
        private readonly int $searchDepth = 50
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function calls(string $profileId, bool $bodies): array
    {
        $profile = $this->find($profileId, static fn (array $section): bool
            => (int) ($section['summary']['count'] ?? 0) > 0);

        if (!isset($profile['id'])) {
            return $profile;
        }

        $section = $this->section($profile);
        $calls = array_map(static function (array $call) use ($bodies): array {
            if (!$bodies) {
                unset($call['request'], $call['response']);
            }

            return $call;
        }, array_values(array_filter((array) ($section['payload']['items'] ?? []), 'is_array')));

        $summary = (array) ($section['summary'] ?? []);

        return $this->envelope([
            'profile_id' => $profile['id'],
            'request' => $this->requestLine($profile),
            'summary' => [
                'count' => $summary['count'] ?? 0,
                'failed_count' => $summary['failed_count'] ?? 0,
                'slow_count' => $summary['slow_count'] ?? 0,
                'slow_threshold_ms' => $summary['slow_threshold_ms'] ?? null,
                'call_ms' => $summary['call_ms'] ?? 0,
            ],
            'calls' => $calls,
            'findings' => $this->findings($profile),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function prices(string $profileId, ?int $productId): array
    {
        $profile = $this->find($profileId, static fn (array $section): bool
            => is_array($section['payload']['prices'] ?? null));

        if (!isset($profile['id'])) {
            return $profile;
        }

        $payload = (array) ($this->section($profile)['payload'] ?? []);
        $prices = (array) ($payload['prices'] ?? []);
        $raw = $this->rawByProduct($payload);
        $products = [];

        foreach ((array) ($prices['products'] ?? []) as $product) {
            $id = (int) ($product['product_id'] ?? 0);

            if ($productId !== null && $id !== $productId) {
                continue;
            }

            $products[] = [
                ...$product,
                'source' => isset($raw[$id]) ? 'bc' : 'price_cache',
                'bc_base_price' => isset($raw[$id]) ? BandDiff::basePrice($raw[$id]['prices']) : null,
                'bc_bands' => $raw[$id]['prices'] ?? null,
                'dropped_fields' => $raw[$id]['dropped'] ?? [],
            ];
        }

        $summary = (array) ($this->section($profile)['summary'] ?? []);

        return $this->envelope([
            'profile_id' => $profile['id'],
            'request' => $this->requestLine($profile),
            'customer_id' => $prices['customer_id'] ?? null,
            'page_product_id' => $prices['page_product_id'] ?? null,
            'requested' => $prices['requested'] ?? [],
            'cache' => [
                'requested' => $summary['cache_requested'] ?? 0,
                'missed' => $summary['cache_missed'] ?? 0,
                'miss_rate' => $summary['cache_miss_rate'] ?? null,
            ],
            'context' => $payload['context'] ?? [],
            'products' => $products,
            'note' => 'final_price comes from priceConfig, html_amount from the price HTML (list items have only '
                . 'that). bc_base_price is the band for a quantity of one, before use_special_price applies.',
            'findings' => $this->findings($profile),
        ]);
    }

    /**
     * The profile asked for, or the newest one whose Commerce365 section passes the test.
     *
     * @param callable(array<string, mixed>): bool $wanted
     * @return array<string, mixed> a profile, or an error envelope
     */
    private function find(string $profileId, callable $wanted): array
    {
        if ($profileId !== '') {
            $profile = $this->store->get($profileId);

            if ($profile === null) {
                return $this->envelope(
                    ['error' => sprintf('No profile "%s". It may have been pruned.', $profileId)],
                    'error'
                );
            }

            return $this->section($profile) === []
                ? $this->envelope(['error' => 'This profile has no Commerce365 section. It is switched off, or the '
                    . 'profile is older than the add-on.'], 'error')
                : $profile;
        }

        foreach ($this->store->recent($this->searchDepth) as $profile) {
            $section = $this->section($profile);

            if ($section !== [] && $wanted($section)) {
                return $profile;
            }
        }

        return $this->envelope([
            'error' => sprintf('None of the last %d profiles has this Commerce365 data. Load a priced page as a '
                . 'customer linked to Business Central, then ask again.', $this->searchDepth),
        ], 'error');
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function section(array $profile): array
    {
        return (array) ($profile['sections'][Commerce365Collector::SECTION] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{prices: array<array-key, mixed>, dropped: list<string>}>
     */
    private function rawByProduct(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['raw'] ?? []) as $capture) {
            foreach ((array) ($capture['bands'] ?? []) as $product) {
                $out[(int) ($product['product_id'] ?? 0)] = [
                    'prices' => (array) ($product['prices'] ?? []),
                    'dropped' => [],
                ];
            }

            foreach ((array) ($capture['diff']['products'] ?? []) as $product) {
                $id = (int) ($product['product_id'] ?? 0);
                $dropped = [];

                foreach ((array) ($product['bands'] ?? []) as $band) {
                    array_push($dropped, ...(array) ($band['dropped'] ?? []));
                }

                $out[$id] = ['prices' => $out[$id]['prices'] ?? [], 'dropped' => array_values(array_unique($dropped))];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<array<string, mixed>>
     */
    private function findings(array $profile): array
    {
        return array_values(array_filter(
            (array) ($profile['findings'] ?? []),
            static fn (mixed $finding): bool => is_array($finding)
                && ($finding['section'] ?? '') === Commerce365Collector::SECTION
        ));
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function requestLine(array $profile): array
    {
        $request = (array) ($profile['sections']['request']['summary'] ?? []);

        return [
            'method' => $request['method'] ?? null,
            'path' => $request['path'] ?? null,
            'area' => $request['area'] ?? null,
            'action' => $request['action'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function envelope(array $body, string $status = 'ok'): array
    {
        return ['response_version' => self::RESPONSE_VERSION, 'status' => $status, ...$body];
    }
}
