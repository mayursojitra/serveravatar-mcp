<?php

namespace App\Enums;

/** What a connected AI client may do (FR-11). Each level includes the ones below it. */
enum PermissionLevel: string
{
    case Read = 'read';
    case Action = 'action';
    case Full = 'full';

    public function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::Action => 2,
            self::Full => 3,
        };
    }

    /** True when a connection at this level may use a tool that needs $required. */
    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::Action => 'Read + safe actions',
            self::Full => 'Full',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Read => 'List and inspect servers, apps, domains and more. Changes nothing.',
            self::Action => 'Adds safe actions such as restarting a server or controlling a supervisor process.',
            self::Full => 'Adds creating things: domains, SSL certificates, databases and cron jobs.',
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $level) => [
            'value' => $level->value,
            'label' => $level->label(),
            'description' => $level->description(),
        ], self::cases());
    }
}
