<?php

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [
    /*
    | Where an AI client may be sent back to after the user approves a connection. Each entry is a
    | scheme and host; a client cannot register a redirect outside this list. The default allows
    | claude.ai and the localhost callbacks used by Claude Code and similar tools. Never use "*".
    */
    'redirect_domains' => $list((string) env('MCP_REDIRECT_DOMAINS', 'https://claude.ai,https://claude.com,http://localhost,http://127.0.0.1')),

    // Private-use URI schemes of desktop clients (RFC 8252), such as cursor:// and vscode://.
    'custom_schemes' => $list((string) env('MCP_CUSTOM_SCHEMES', 'cursor,vscode')),

    // Extra browser origins allowed to call /mcp (the app's own URL and localhost always are).
    'allowed_origins' => $list((string) env('MCP_ALLOWED_ORIGINS', '')),

    'authorization_server' => null,
];
