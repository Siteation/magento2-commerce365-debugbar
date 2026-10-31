<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Collector;

use Commerce365\CustomerPrice\Model\CachedPrice;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ResponseInterface;
use Siteation\Commerce365DebugBar\Model\BandDiff;
use Siteation\Commerce365DebugBar\Model\PriceDecoder;
use Siteation\DebugBar\Collector\AbstractCollector;
use Siteation\DebugBar\Model\Clock;
use Siteation\DebugBar\Model\Config;
use Siteation\DebugBar\Model\Redactor;
use Throwable;

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

    /** Commerce365's own warm-up of the price cache while a product list renders */
    public const ORIGIN_PRELOAD = 'preload';

    /** @var array{status: int|null, bytes: int|null}|null */
    private ?array $response = null;

    /** @var array<string, mixed>|null */
    private ?array $prices = null;

    /** @var array{requested: list<int>, missed: list<int>, lookups: int} */
    private array $cache = ['requested' => [], 'missed' => [], 'lookups' => 0];

    /** @var array{requested: list<int>, missed: list<int>, calls: int} */
    private array $preload = ['requested' => [], 'missed' => [], 'calls' => 0];

    /** What Commerce365 is doing when a call or a lookup comes in, null outside a known caller */
    private ?string $origin = null;

    /** @var array<int, array<string, mixed>> the price cache rows the request was priced from, by product */
    private array $cached = [];

    /** @var list<array{request: mixed, response: mixed}> the bodies of the calls, beside the items */
    private array $bodies = [];

    /** @var list<array<string, mixed>> */
    private array $raw = [];

    /** @var array<string, mixed> */
    private array $context = [];

    private ?bool $loggedIn = null;

    private int $failed = 0;

    private int $slow = 0;

    private float $callMs = 0.0;

    public function __construct(
        Redactor $redactor,
        Clock $clock,
        private readonly Config $config,
        private readonly PriceDecoder $decoder,
        private readonly BandDiff $bandDiff,
        private readonly HttpContext $httpContext,
        private readonly Redactor $bodyRedactor,
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
        $this->preload = ['requested' => [], 'missed' => [], 'calls' => 0];
        $this->origin = null;
        $this->cached = [];
        $this->bodies = [];
        $this->raw = [];
        $this->context = [];
        $this->loggedIn = null;
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
     * @param Throwable|null $failure what the call threw, if it did
     */
    public function recordCall(
        string $transport,
        string $httpMethod,
        string $endpoint,
        float $startMs,
        float $durationMs,
        array $request,
        mixed $result,
        ?Throwable $failure = null
    ): void {
        $status = $this->response['status'] ?? null;
        $bytes = $this->response['bytes'] ?? null;
        $this->response = null;

        // No status means the request never got an answer: no client configured, a
        // connection error, or an exception Commerce365 logged and swallowed.
        $ok = $failure === null && $status !== null && $status >= 200 && $status < 300;
        $policy = $this->config->valuePolicy();

        if ($this->origin === self::ORIGIN_PRELOAD) {
            $this->preload['calls']++;
        }

        // The bodies sit beside the items, under the item's index, not in them: record()
        // bounds an item at five levels, and a price band is six deep in a Hub answer, so
        // every value in it read "[maximum depth reached]". The body redactor goes deeper.
        $index = count($this->items);

        if ($index < $this->maxItems) {
            $this->bodies[$index] = [
                'request' => $this->bodyRedactor->cleanValues($this->withoutCredentials($request), $policy),
                'response' => $this->body($result, $policy),
            ];
        }

        $this->record([
            'name' => sprintf('%s %s %s', $this->transportLabel($transport), $httpMethod, $endpoint),
            'transport' => $transport,
            'method' => $httpMethod,
            'endpoint' => $endpoint,
            'status' => $status,
            'ok' => $ok,
            'error' => $this->error($failure, $policy),
            'bytes' => $bytes,
            'start_ms' => round($startMs, 3),
            'at_ms' => round($startMs + $durationMs, 3),
            'duration_ms' => round($durationMs, 3),
            'slow' => $durationMs >= $this->slowCallMs,
            'origin' => $this->origin,
        ]);
    }

    /**
     * Runs what Commerce365 does for one known caller, so its calls and lookups are told
     * apart from the rest. ProductListPricesPreloadToCache fills the price cache while a
     * product list renders, on purpose: its misses are what spare the customer-price
     * request a BC call.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function during(string $origin, callable $work): mixed
    {
        $previous = $this->origin;
        $this->origin = $origin;

        try {
            return $work();
        } finally {
            $this->origin = $previous;
        }
    }

    /**
     * @param list<int> $productIds
     */
    public function recordCacheLookup(array $productIds): void
    {
        if ($this->origin === self::ORIGIN_PRELOAD) {
            $this->preload['requested'] = $this->ids([...$this->preload['requested'], ...$productIds]);

            return;
        }

        $this->cache['lookups']++;
        $this->cache['requested'] = $this->ids([...$this->cache['requested'], ...$productIds]);
    }

    /**
     * @param list<int> $productIds the products the price cache did not have, fetched from BC
     */
    public function recordCacheMiss(array $productIds): void
    {
        if ($this->origin === self::ORIGIN_PRELOAD) {
            $this->preload['missed'] = $this->ids([...$this->preload['missed'], ...$productIds]);

            return;
        }

        $this->cache['missed'] = $this->ids([...$this->cache['missed'], ...$productIds]);
    }

    /**
     * The price cache rows a lookup answered with, cached or just fetched. The only amount a
     * list item with a from-price has: its HTML prints a price per unit, and no amount.
     *
     * @param iterable<mixed> $collection what GetPriceCollectionForProducts returned
     */
    public function recordCachedPrices(iterable $collection): void
    {
        $full = $this->config->valuePolicy() === Redactor::POLICY_FULL;

        foreach ($collection as $item) {
            if (!$item instanceof CachedPrice) {
                continue;
            }

            $tiers = array_map(static fn (mixed $tier): array => [
                'qty' => is_array($tier) ? ($tier['qty'] ?? null) : null,
                'price' => is_array($tier) ? ($tier['price'] ?? null) : null,
            ], array_values((array) $item->getTierPrices()));

            $row = ['price' => $item->getPrice(), 'special_price' => $item->getSpecialPrice(), 'tiers' => $tiers];

            // Numbers only, and under another policy only their type, like the raw bands
            $this->cached[(int) $item->getProductId()] = $full ? $row : [
                'price' => get_debug_type($row['price']),
                'special_price' => get_debug_type($row['special_price']),
                'tiers' => array_map(static fn (array $tier): array => array_map('get_debug_type', $tier), $tiers),
            ];
        }
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
            // Whose lookups those are: the customer-price request's, or a page's own
            'cache_scope' => $this->prices !== null ? 'price_request' : 'page',
            'preload_call_count' => $this->preload['calls'],
            'preload_requested' => count($this->preload['requested']),
            'preload_missed' => count($this->preload['missed']),
            'dropped_fields' => $this->droppedFields(),
        ];
    }

    public function payload(): array
    {
        return [
            'items' => $this->items,
            'prices' => $this->prices,
            'bodies' => $this->bodies,
            'cache' => $this->cache,
            'preload' => $this->preload,
            'cached' => $this->cached,
            'raw' => $this->raw,
            'context' => $this->context,
            'logged_in' => $this->loggedIn,
        ];
    }

    /**
     * Whether a customer was signed in, as the full page cache varies on it. Read from the
     * HTTP context rather than the session, so a cached page that never started a session
     * does not start one here.
     */
    public function finalize(ResponseInterface $response): void
    {
        $this->loggedIn = (bool) $this->httpContext->getValue('customer_logged_in');
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
     * What a call answered, under the same policy as everything else. Commerce365's request
     * services all return an array today; anything else is held to the policy all the same
     * rather than stored as it came.
     */
    private function body(mixed $result, string $policy): mixed
    {
        if (is_array($result)) {
            return $this->bodyRedactor->cleanValues($result, $policy);
        }

        return match ($policy) {
            Redactor::POLICY_NONE => null,
            Redactor::POLICY_MASKED => get_debug_type($result),
            default => $this->bodyRedactor->clean($result),
        };
    }

    /**
     * The message only under full, as the debugbar treats an exception's message. A Guzzle
     * connect error names the whole request URL, query string and all, and the class alone
     * still says whether the Hub was unreachable or the call itself went wrong.
     */
    private function error(?Throwable $failure, string $policy): ?string
    {
        if ($failure === null) {
            return null;
        }

        return $policy === Redactor::POLICY_FULL ? $failure->getMessage() : $failure::class;
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
