<?php

declare(strict_types=1);

namespace Siteation\Commerce365DebugBar\Mcp\Tool;

use Siteation\Commerce365DebugBar\Mcp\Commerce365Profiles;
use Siteation\DebugBar\Api\McpToolInterface;

class GetCalls implements McpToolInterface
{
    public function __construct(private readonly Commerce365Profiles $profiles)
    {
    }

    public function name(): string
    {
        return 'get_c365_calls';
    }

    public function description(): string
    {
        return 'List the Commerce365 Hub and Business Central calls one request made: transport, method, '
            . 'endpoint, HTTP status, duration and size, with the Commerce365 findings. Without a profile id, '
            . 'reads the newest profile that made a call. A price served from the Commerce365 price cache makes '
            . 'no call.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile_id' => [
                    'type' => 'string',
                    'description' => 'Profile id, as returned by list_debug_profiles or the '
                        . 'X-Siteation-DebugBar-Profile response header. Optional.',
                ],
                'bodies' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include the request and response bodies, redacted and capped.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        return $this->profiles->calls(
            (string) ($arguments['profile_id'] ?? ''),
            (bool) ($arguments['bodies'] ?? false)
        );
    }
}
