# Siteation_Commerce365DebugBar

[![Packagist Version](https://img.shields.io/packagist/v/siteation/magento2-commerce365-debugbar?style=for-the-badge)](https://packagist.org/packages/siteation/magento2-commerce365-debugbar)
![Supported PHP Versions](https://img.shields.io/badge/php-%208.2_|_8.3_|_8.4_|_8.5-8892bf.svg?logo=php&logoColor=white&longCache=true&style=for-the-badge)
![License](https://img.shields.io/github/license/Siteation/magento2-commerce365-debugbar?color=%23234&style=for-the-badge)

Adds a **Commerce365** section to [Siteation_DebugBar](https://github.com/Siteation/magento2-debugbar):
the customer prices a request answered, every Hub and Business Central call, the price cache, and the
raw BC price bands with the fields the storefront drops.

Needs Siteation_DebugBar 1.5.1 or newer. 1.4.0 brought the section API, 1.5.0 fixed two security
issues in the bar itself, and 1.5.1 labels this add-on's MCP results as recorded data.
Hyvä first. Luma is untested.

## Install

From [Packagist](https://packagist.org/packages/siteation/magento2-commerce365-debugbar), on a store
that runs Commerce365:

```sh
composer require --dev siteation/magento2-commerce365-debugbar
bin/magento module:enable Siteation_DebugBar Siteation_Commerce365DebugBar
bin/magento setup:upgrade
```

Composer brings in Siteation_DebugBar 1.5.1 or newer if the store does not have it yet. The
Commerce365 modules it plugs into (`commerce365/module-core` and `commerce365/module-customer-price`,
2.x) are expected to be installed already. `--dev` on purpose: this is a development tool, and a
production build should not carry its plugins.

Turn the bar on as described in the
[Siteation_DebugBar install steps](https://github.com/Siteation/magento2-debugbar#install). The
Commerce365 section then appears on its own, and can be switched off under **Sections** like any
other.

Remove `Siteation_Commerce365Debug` (the old bridge) at the same time, so the two do not both plug
into the price request.

## What is recorded

One collector, `c365`, the same id as the section, so the debugbar's Sections setting switches both.

| Hook | Records |
| --- | --- |
| `Core\Service\Request\Post`, `Get`, `BusinessCentral\BasicPost`, `OAuthPost` | One span per call: transport, method, endpoint, ms, request body, decoded response |
| `Core\Service\Response\ProcessResponse` (Hub and BC) | The HTTP status and size, which Commerce365 drops |
| `CustomerPrice\Api\CustomerPriceInterface::getCustomerPrice` | The customer-price request, decoded per product, the B2B parent, the price settings |
| `GetPriceCollectionForProducts`, `SyncPrices` | Price cache lookups and misses |
| `GetCustomerPrices::execute` | Raw BC `priceLists`, and which band fields are used or dropped |

Auth, `headers`, `appId`, `secretKey` and OAuth tokens are never recorded. Raw bands keep their values
only under the `full` value policy; otherwise they keep keys and types.

## On the page

The section has six tabs. Prices, Calls and Config come from the stored profile. On a page profile,
which holds no prices because they load afterwards, the page script finds the newest customer-price
request in the bar's request list, loads its profile and shows its tabs, so you stay on the page.

| Tab | Source | Shows |
| --- | --- | --- |
| Prices | stored | Per product: final (or the amount the list HTML printed), old, tiers, BC price, cache or BC, dropped BC fields; raw bands |
| Calls | stored | Every Hub and BC call with status, ms and bytes; bodies below |
| Config | stored | Customer, the B2B parent whose prices apply, the Commerce365 price settings |
| Coverage | live | Per product: price boxes on the page and their state, asked for, answered, shown |
| Events | live | `commerce365:prices-updated` and quantity or unit changes, newest first |
| Page | live | PDP or list, whether customer prices are on, every price box; hover a row to outline it |

The live half reads what `Nebula_Commerce365Compat` (NFT) puts on the page: `[data-role=priceBox][data-product-id]`
with `data-commerce365-price`, the `commerce365` key of `#nft-globals`, and the `commerce365:prices-updated` event.

## Findings

| Severity | Id | When |
| --- | --- | --- |
| error | `c365.call_failed` | A Hub or BC call got no 2xx answer, which Commerce365 otherwise hides |
| error | `c365.call_in_page_render` | Commerce365 called out while a storefront page rendered, outside its own price cache warm-up; or the warm-up ran on a page that was not stored |
| warning | `c365.call_slow` | A call over the threshold (1000 ms, `slowCallMs` on the collector) |
| warning | `c365.cache_miss_rate` | 50% or more of 10 or more products missed the price cache, warm-up lookups left out; the message names the request |
| info | `c365.guest_on_priced_page` | Logged out on a category, product or search page |
| info | `c365.dropped_fields` | BC sent band fields the storefront never reads |
| info | `c365.price_cache_warmup` | `ProductListPricesPreloadToCache` fetched the list's prices from BC while it rendered, on a page that was stored |

Thresholds are constructor arguments, so a project changes them in its own di.xml.

## MCP

Two tools on the debugbar's MCP server, no extra registration:

- `get_c365_calls` (`profile_id`, `bodies`): the calls of a request. Without an id, the newest request that made one.
- `get_c365_prices` (`profile_id`, `product_id`): decoded prices, BC bands, dropped fields, customer, settings,
  cache. Without an id, the newest customer-price request.

## Develop

The unit tests borrow a Commerce365 shop's `vendor/` for Magento and the Commerce365 modules, and a
debugbar checkout:

```sh
C365_HOST=~/Developer/your-shop C365_DEBUGBAR=~/Developer/magento2-debugbar \
  php ~/Developer/your-shop/vendor/bin/phpunit
php ~/Developer/your-shop/vendor/bin/phpstan analyse -c phpstan.neon.dist --autoload-file dev/bootstrap.php
php ~/Developer/your-shop/vendor/bin/phpcs --standard=phpcs.xml.dist .
npm test   # node --test, the live half
```

## Licence

MIT. See [LICENSE](LICENSE).
