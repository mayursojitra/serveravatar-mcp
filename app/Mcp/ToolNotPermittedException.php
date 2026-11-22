<?php

namespace App\Mcp;

use Laravel\Mcp\Exceptions\JsonRpcException;

/** A tool exists but the connection's permission level is too low for it. Logged as "denied". */
class ToolNotPermittedException extends JsonRpcException {}
