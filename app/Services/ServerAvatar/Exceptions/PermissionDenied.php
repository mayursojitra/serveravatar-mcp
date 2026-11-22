<?php

namespace App\Services\ServerAvatar\Exceptions;

/** 403: the token's user lacks permission for this action (for example an organization-level list). */
final class PermissionDenied extends ServerAvatarException {}
