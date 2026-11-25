<?php

namespace App\Enums;

/** What kind of event an activity log row records. */
enum ActivityKind: string
{
    case AuthLogin = 'auth.login';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthPasswordChanged = 'auth.password_changed';
    case AuthPasswordResetCompleted = 'auth.password_reset_completed';
    case AuthTwoFactorEnabled = 'auth.two_factor_enabled';
    case AuthTwoFactorDisabled = 'auth.two_factor_disabled';
    case AuthTwoFactorFailed = 'auth.two_factor_failed';
    case AuthTwoFactorRecoveryUsed = 'auth.two_factor_recovery_used';
    case AuthTwoFactorCodesRegenerated = 'auth.two_factor_codes_regenerated';

    case McpInitialize = 'mcp.initialize';
    case McpToolsList = 'mcp.tools_list';
    case McpToolCall = 'mcp.tool_call';
    case McpPing = 'mcp.ping';
    case McpOther = 'mcp.other';
    case McpInvalidRequest = 'mcp.invalid_request';
    case McpRejected = 'mcp.rejected';
    case McpConnectionAuthorized = 'mcp.connection_authorized';
    case McpConnectionLevelChanged = 'mcp.connection_level_changed';
    case McpConnectionRevoked = 'mcp.connection_revoked';

    case TokenSaved = 'token.saved';
    case TokenReplaced = 'token.replaced';
    case TokenVerifyFailed = 'token.verify_failed';
    case TokenTested = 'token.tested';
    case TokenDeleted = 'token.deleted';

    case AdminAdminCreated = 'admin.admin_created';
    case AdminUserCreated = 'admin.user_created';
    case AdminUserUpdated = 'admin.user_updated';
    case AdminUserDisabled = 'admin.user_disabled';
    case AdminUserEnabled = 'admin.user_enabled';
    case AdminPasswordReset = 'admin.password_reset';
    case AdminTwoFactorReset = 'admin.two_factor_reset';
    case AdminUserDeleted = 'admin.user_deleted';
}
