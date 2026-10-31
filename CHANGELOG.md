# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

[Unreleased]: https://github.com/Siteation/magento2-commerce365-debugbar/compare/1.0.0...main

## [1.0.0] - 2026-10-31

[1.0.0]: https://github.com/Siteation/magento2-commerce365-debugbar/releases/tag/1.0.0

The first release.

### Added

- A **Commerce365** section for Siteation_DebugBar: the customer prices a request answered, every Hub and
  Business Central call as a span on the timeline, the price cache hits and misses, and the raw BC price bands
  with the fields the storefront drops.
- A live half on the page: price box coverage, the `commerce365:*` price events, and the customer-price request
  the page made, linked in so the developer can stay on the page.
- Findings for failed, slow and in-render calls, a high cache miss rate, a guest on a priced page, and dropped
  BC fields.
- MCP tools `get_c365_calls` and `get_c365_prices`.

### Security

- Requires Siteation_DebugBar 1.5.1 or newer, so a shop cannot stay on a version with the 1.4.1 advisories, and
  this add-on's MCP results carry the recorded data note.
- A failed call's exception message is kept under the `full` value policy only, and a call result that is not
  an array is held to the policy like every other body.
