<?php

namespace App\Services\ServerAvatar\Exceptions;

/** 429: ServerAvatar's rate limit was hit. */
final class RateLimited extends ServerAvatarException {}
