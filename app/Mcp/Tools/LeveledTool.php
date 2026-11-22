<?php

namespace App\Mcp\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\McpConnection;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for every tool. A tool declares the lowest permission level that may use it; above a
 * connection's level it is not listed (FR-11) and a call is refused as "not permitted" and logged.
 */
abstract class LeveledTool extends Tool
{
    abstract public function requiredLevel(): PermissionLevel;

    /**
     * A tool without parameters must advertise `"properties": {}`. laravel/mcp's fallback for that only
     * applies when the key is missing, so an empty list slips through as `[]`, which is not a valid
     * JSON Schema and can make a strict client reject the tool.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();

        if (($tool['inputSchema']['properties'] ?? null) === []) {
            $tool['inputSchema']['properties'] = (object) [];
        }

        return $tool;
    }

    /** Called by laravel/mcp when building the tool list. */
    public function shouldRegister(McpConnection $connection): bool
    {
        return $connection->level()->allows($this->requiredLevel());
    }
}
