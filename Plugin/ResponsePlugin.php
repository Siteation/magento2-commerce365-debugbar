<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Plugin;

use Closure;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Model\ProfileManager;

/**
 * The HTTP status of a Hub or BC response, read before Commerce365 throws it away.
 *
 * Core builds its Guzzle client with http_errors off, and both response processors return
 * an empty array for anything outside 2xx. Without this a 500 from the Hub and a product
 * that has no price look the same.
 */
class ResponsePlugin
{
    public function __construct(
        private readonly ProfileManager $manager,
        private readonly Commerce365Collector $collector
    ) {
    }

    public function aroundExecute(object $subject, Closure $proceed, mixed $response): mixed
    {
        if ($this->manager->isCollecting()) {
            $this->manager->quietly(Commerce365Collector::SECTION, fn () => $this->collector->noteResponse(
                $this->status($response),
                $this->bytes($response)
            ));
        }

        return $proceed($response);
    }

    private function status(mixed $response): ?int
    {
        return is_object($response) && method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : null;
    }

    private function bytes(mixed $response): ?int
    {
        if (!is_object($response) || !method_exists($response, 'getBody')) {
            return null;
        }

        $body = $response->getBody();
        $size = is_object($body) && method_exists($body, 'getSize') ? $body->getSize() : null;

        return is_int($size) ? $size : null;
    }
}
