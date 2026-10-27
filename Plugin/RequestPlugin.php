<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Plugin;

use Closure;
use Commerce365\Core\Service\Request\BusinessCentral\BasicPost;
use Commerce365\Core\Service\Request\BusinessCentral\OAuthPost;
use Commerce365\Core\Service\Request\Get;
use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Model\Clock;
use Siteation\DebugBar\Model\ProfileManager;
use Throwable;

/**
 * Every call Commerce365 Core makes to the Hub or to Business Central, timed as a span.
 *
 * One around plugin on the four request services: the Hub's Post and Get, and BC's
 * BasicPost and OAuthPost. Every other Commerce365 module goes through one of them, so
 * sales document history calls are recorded too, without a plugin of their own.
 *
 * Variadic, because Get takes ($endpoint, $query) and the three posts take ($method,
 * $postData), and one plugin class reads both.
 */
class RequestPlugin
{
    public function __construct(
        private readonly ProfileManager $manager,
        private readonly Commerce365Collector $collector,
        private readonly Clock $clock
    ) {
    }

    public function aroundExecute(object $subject, Closure $proceed, mixed ...$arguments): mixed
    {
        if (!$this->manager->isCollecting()) {
            return $proceed(...$arguments);
        }

        $this->collector->openCall();
        $startMs = $this->clock->elapsedMs();
        $startedAt = microtime(true);

        try {
            $result = $proceed(...$arguments);
        } catch (Throwable $thrown) {
            $this->record($subject, $arguments, $startMs, $startedAt, null, $thrown->getMessage());

            throw $thrown;
        }

        $this->record($subject, $arguments, $startMs, $startedAt, $result, null);

        return $result;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function record(
        object $subject,
        array $arguments,
        float $startMs,
        float $startedAt,
        mixed $result,
        ?string $error
    ): void {
        $durationMs = (microtime(true) - $startedAt) * 1000;

        $this->manager->quietly(Commerce365Collector::SECTION, function () use (
            $subject,
            $arguments,
            $startMs,
            $durationMs,
            $result,
            $error
        ): void {
            $isGet = $subject instanceof Get;
            $sent = $arguments[1] ?? [];

            $this->collector->recordCall(
                $this->transport($subject),
                $isGet ? 'GET' : 'POST',
                is_scalar($arguments[0] ?? null) ? (string) $arguments[0] : '?',
                $startMs,
                $durationMs,
                // A post sends its body as Guzzle's json option; the rest of the options
                // are transport settings, and auth among them.
                is_array($sent) ? ($isGet ? $sent : (array) ($sent['json'] ?? [])) : [],
                $result,
                $error
            );
        });
    }

    private function transport(object $subject): string
    {
        return match (true) {
            $subject instanceof BasicPost => 'bc_basic',
            $subject instanceof OAuthPost => 'bc_oauth',
            default => 'hub',
        };
    }
}
