<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Analysis\Rule;

use Siteation\Commerce365DebugBar\Collector\Commerce365Collector;
use Siteation\DebugBar\Api\RuleInterface;

/**
 * What every Commerce365 rule reads: its own section, and the request it belongs to.
 */
abstract class AbstractRule implements RuleInterface
{
    protected const SECTION = Commerce365Collector::SECTION;

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    protected function summary(array $profile): array
    {
        return (array) ($profile['sections'][self::SECTION]['summary'] ?? []);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    protected function payload(array $profile): array
    {
        return (array) ($profile['sections'][self::SECTION]['payload'] ?? []);
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<array<string, mixed>>
     */
    protected function calls(array $profile): array
    {
        return array_values(array_filter((array) ($this->payload($profile)['items'] ?? []), 'is_array'));
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    protected function request(array $profile): array
    {
        return (array) ($profile['sections']['request']['summary'] ?? []);
    }

    /**
     * @return array{label: string, section: string}
     */
    protected function action(string $label): array
    {
        return ['label' => $label, 'section' => self::SECTION];
    }
}
