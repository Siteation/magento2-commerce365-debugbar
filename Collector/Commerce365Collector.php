<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Collector;

use Siteation\Commerce365DebugBar\Model\BandDiff;
use Siteation\Commerce365DebugBar\Model\PriceDecoder;
use Siteation\DebugBar\Collector\AbstractCollector;
use Siteation\DebugBar\Model\Clock;
use Siteation\DebugBar\Model\Config;
use Siteation\DebugBar\Model\Redactor;

/**
 * Everything Commerce365 did during one request: its Hub and Business Central calls, the
 * customer-price request and what it answered, the price cache, and the raw BC bands.
 *
 * One collector rather than one per concern, because the debugbar switches a contributed
 * section and its collector by the same id. Three collectors under three ids would keep
 * recording when the Commerce365 section is switched off.
 *
 * The calls are the items, so the debugbar's timeline draws each one as a span. The rest
 * of the payload sits beside them under its own key.
 */
class Commerce365Collector extends AbstractCollector
{
    public const SECTION = 'c365';

    /** @var array{status: int|null, bytes: int|null}|null */
    private ?array $response = null;

    /** @var array<string, mixed>|null */
    private ?array $prices = null;

    /** @var array{requested: list<int>, missed: list<int>, lookups: int} */
    private array $cache = ['requested' => [], 'missed' => [], 'lookups' => 0];

    /** @var list<array<string, mixed>> */
    private array $raw = [];

    /** @var array<string, mixed> */
    private array $context = [];

    private int $failed = 0;

    private int $slow = 0;

    private float $callMs = 0.0;

    public function __construct(
        Redactor $redactor,
        Clock $clock,
        private readonly Config $config,
        private readonly PriceDecoder $decoder,
        private readonly BandDiff $bandDiff,
        private readonly float $slowCallMs = 1000.0,
        int $maxItems = 200
    ) {
        parent::__construct($redactor, $clock, $maxItems);
    }

    public function label(): string
    {
        return 'Commerce365';
    }

    public function reset(): void
    {
        parent::reset();
        $this->response = null;
        $this->prices = null;
        $this->cache = ['requested' => [], 'missed' => [], 'lookups' => 0];
        $this->raw = [];
        $this->context = [];
        $this->failed = 0;
        $this->slow = 0;
        $this->callMs = 0.0;
    }

    /**
     * Commerce365 turns the HTTP response into an array before the caller sees it, and
     * drops the status on the way. The response processor hands it over here, and the call
     * that is still open picks it up when it ends.
     */
    public function noteResponse(?int $status, ?int $bytes): void
    {
        $this->response = ['status' => $status, 'bytes' => $bytes];
    }

    /**
     * Called before a call starts, so a status left over from an earlier call that never
     * reached its processor cannot be read as this one's.
     */
    public function openCall(): void
    {
        $this->response = null;
    }

    /**
     * @param string $transport hub, bc_basic or bc_oauth
     * @param string $endpoint the Hub method or BC function, never the full URL with credentials
     * @param array<array-key, mixed> $request what was sent, without auth or headers
     * @param mixed $result what Commerce365 got back, already decoded
     */
    public function recordCall(
        string $transport,
        string $httpMethod,
        string $endpoint,
        float $startMs,
        float $durationMs,
        array $request,
        mixed $result,
        ?string $error = null
    ): void {
        $status = $this->response['status'] ?? null;
        $bytes = $this->response['bytes'] ?? null;
        $this->response = null;

        // No status means the request never got an answer: no client configured, a
        // connection error, or an exception Commerce365 logged and swallowed.
        $ok = $error === null && $status !== null && $status >= 200 && $status < 300;
        $policy = $this->config->valuePolicy();

        $this->record([
            'name' => sprintf('%s %s %s', $this->transportLabel($transport), $httpMethod, $endpoint),
            'transport' => $transport,
            'method' => $httpMethod,
            'endpoint' => $endpoint,
            'status' => $status,
            'ok' => $ok,
            'error' => $error,
            'bytes' => $bytes,
            'start_ms' => round($startMs, 3),
            'at_ms' => round($startMs + $durationMs, 3),
            'duration_ms' => round($durationMs, 3),
            'slow' => $durationMs >= $this->slowCallMs,
            'request' => $this->redactor->cleanValues($this->withoutCredentials($request), $policy),
            'response' => is_array($result) ? $this->redactor->cleanValues($result, $policy) : $result,
        ]);
    }

    /**
     * @param list<int> $productIds
     */
    public function recordCacheLookup(array $productIds): void
    {
        $this->cache['lookups']++;
        $this->cache['requested'] = $this->ids([...$this->cache['requested'], ...$productIds]);
    }

    /**
     * @param list<int> $productIds the products the price cache did not have, fetched from BC
     */
    public function recordCacheMiss(array $productIds): void
    {
        $this->cache['missed'] = $this->ids([...$this->cache['missed'], ...$productIds]);
    }

    /**
     * The priceLists exactly as BC returned them, before Commerce365 maps them into its cache.
     *
     * @param list<int> $productIds
     */
    public function recordRawPrices(array $productIds, mixed $priceLists): void
    {
        $diff = $this->bandDiff->diff($priceLists);
        $bands = [];

        foreach (is_array($priceLists) ? $priceLists : [] as $priceList) {
            $productId = is_array($priceList) ? ($priceList['productId'] ?? $priceList['ProductId'] ?? null) : null;
            $bands[] = [
                'product_id' => is_numeric($productId) ? (int) $productId : null,
                'prices' => $this->bandValues(is_array($priceList) ? ($priceList['prices'] ?? []) : []),
            ];
        }

        $this->raw[] = [
            'at_ms' => $this->clock->elapsedMs(),
            'product_ids' => $this->ids($productIds),
            'bands' => $bands,
            'diff' => $diff,
        ];
    }

    /**
     * The customer-price request and its answer, decoded per product.
     *
     * @param array<array-key, mixed> $productInfo the product ids the page asked prices for
     * @param mixed $response the array CustomerPrice::getCustomerPrice() returned
     */
    public function recordPriceRequest(
        array $productInfo,
        mixed $storeId,
        mixed $customerId,
        mixed $productId,
        mixed $response,
        float $durationMs
    ): void {
        $products = [];

        foreach (is_array($response) ? $response : [] as $entry) {
            if (is_array($entry)) {
                $products[] = $this->decoder->decode($entry);
            }
        }

        $policy = $this->config->valuePolicy();

        $this->prices = [
            'store_id' => is_numeric($storeId) ? (int) $storeId : null,
            'customer_id' => is_numeric($customerId) ? (int) $customerId : null,
            // 0 on a category page, the product's id on its own page
            'page_product_id' => is_numeric($productId) ? (int) $productId : null,
            'requested' => $this->ids(array_values($productInfo)),
            'duration_ms' => round($durationMs, 3),
            'products' => $policy === Redactor::POLICY_FULL
                ? $products
                : array_map(fn (array $product): array => $this->redactor->cleanValues($product, $policy), $products),
        ];
    }

    /**
     * @param array<string, mixed> $context customer, parent and the Commerce365 settings in effect
     */
    public function recordContext(array $context): void
    {
        $this->context = $this->redactor->cleanValues(
            [...$this->context, ...$context],
            $this->config->valuePolicy()
        );
    }

    public function summary(): array
    {
        $requested = count($this->cache['requested']);
        $missed = count($this->cache['missed']);

        return [
            ...parent::summary(),
            'failed_count' => $this->failed,
            'slow_count' => $this->slow,
            'slow_threshold_ms' => $this->slowCallMs,
            'call_ms' => round($this->callMs, 2),
            'priced_count' => count($this->prices['products'] ?? []),
            'cache_requested' => $requested,
            'cache_missed' => $missed,
            'cache_miss_rate' => $requested > 0 ? round($missed / $requested * 100, 1) : null,
            'dropped_fields' => $this->droppedFields(),
        ];
    }

    public function payload(): array
    {
        return [
            'items' => $this->items,
            'prices' => $this->prices,
            'cache' => $this->cache,
            'raw' => $this->raw,
            'context' => $this->context,
        ];
    }

    protected function track(array $item): void
    {
        $this->callMs += (float) ($item['duration_ms'] ?? 0);

        if (($item['ok'] ?? true) === false) {
            $this->failed++;
        }

        if (($item['slow'] ?? false) === true) {
            $this->slow++;
        }
    }

    /**
     * Every field name BC sent that the storefront drops, with how many bands carried it.
     *
     * @return array<string, int>
     */
    private function droppedFields(): array
    {
        $fields = [];

        foreach ($this->raw as $capture) {
            foreach ($capture['diff']['dropped_fields'] as $field => $count) {
                $fields[$field] = ($fields[$field] ?? 0) + $count;
            }
        }

        arsort($fields);

        return $fields;
    }

    /**
     * Raw bands follow the value policy like everything else, but keep their shape at
     * every level: the keys are the point, because the dropped field diff is read from them.
     *
     * @return list<array<string, mixed>>
     */
    private function bandValues(mixed $bands): array
    {
        $policy = $this->config->valuePolicy();
        $out = [];

        foreach (is_array($bands) ? $bands : [] as $band) {
            if (!is_array($band)) {
                continue;
            }

            $out[] = $policy === Redactor::POLICY_FULL
                ? $this->redactor->cleanValues($band, $policy)
                : array_map(static fn (mixed $value): string => get_debug_type($value), $band);
        }

        return $out;
    }

    /**
     * Commerce365 passes basic auth and the Hub's appId and secretKey through Guzzle options
     * and headers. Neither belongs in a profile, whatever the value policy says.
     *
     * @param array<array-key, mixed> $request
     * @return array<array-key, mixed>
     */
    private function withoutCredentials(array $request): array
    {
        unset($request['auth'], $request['headers']);

        return $request;
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    private function transportLabel(string $transport): string
    {
        return match ($transport) {
            'bc_basic' => 'BC (basic)',
            'bc_oauth' => 'BC (OAuth)',
            default => 'Hub',
        };
    }
}
