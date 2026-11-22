<?php

namespace Tests\Support\Tools;

use App\Enums\PermissionLevel;
use App\Mcp\Tools\LeveledTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Test-only tools at each permission level, so the level gate can be exercised. */
class ReadStubTool extends LeveledTool
{
    protected string $name = 'read_stub';

    protected string $description = 'Read level stub.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['note' => $schema->string()];
    }

    public function handle(Request $request): Response
    {
        return Response::text('read ok');
    }
}

class ActionStubTool extends LeveledTool
{
    protected string $name = 'action_stub';

    protected string $description = 'Action level stub.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Action;
    }

    public function handle(Request $request): Response
    {
        return Response::text('action ok');
    }
}

class FullStubTool extends LeveledTool
{
    protected string $name = 'full_stub';

    protected string $description = 'Full level stub.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Full;
    }

    public function handle(Request $request): Response
    {
        return Response::text('full ok');
    }
}

/** Answers with a tool-level error (the model sees it, the call is logged as failed). */
class FailingStubTool extends LeveledTool
{
    protected string $name = 'failing_stub';

    protected string $description = 'Always reports an error.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    public function handle(Request $request): Response
    {
        return Response::error('ServerAvatar said no');
    }
}

/** Crashes with a message that must never reach the client. */
class CrashingStubTool extends LeveledTool
{
    protected string $name = 'crashing_stub';

    protected string $description = 'Always throws.';

    public function requiredLevel(): PermissionLevel
    {
        return PermissionLevel::Read;
    }

    public function handle(Request $request): Response
    {
        throw new \RuntimeException('internal detail: db password is hunter2');
    }
}
