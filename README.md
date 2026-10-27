# Siteation_Commerce365DebugBar

Adds a **Commerce365** section to [Siteation_DebugBar](https://github.com/Siteation/magento2-debugbar):
the customer prices a request answered, every Hub and Business Central call, the price cache, and the
raw BC price bands with the fields the storefront drops.

Needs Siteation_DebugBar 1.4.0 or newer, the first release with the section API.
Hyvä first. Luma is untested.

## Install

```sh
composer require --dev siteation/magento2-commerce365-debugbar
bin/magento module:enable Siteation_Commerce365DebugBar
bin/magento setup:upgrade
```

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

## Develop

The unit tests borrow a shop's `vendor/` (Commerce365 is not on a public mirror) and a debugbar checkout:

```sh
C365_HOST=~/Developer/your-shop C365_DEBUGBAR=~/Developer/magento2-debugbar \
  php ~/Developer/your-shop/vendor/bin/phpunit
php ~/Developer/your-shop/vendor/bin/phpstan analyse -c phpstan.neon.dist --autoload-file dev/bootstrap.php
php ~/Developer/your-shop/vendor/bin/phpcs --standard=phpcs.xml.dist .
```
