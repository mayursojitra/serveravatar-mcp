<?php

namespace App\Services\ServerAvatar\Exceptions;

/** 401: the token was refused (expired, revoked or wrong). */
final class AuthenticationFailed extends ServerAvatarException {}
