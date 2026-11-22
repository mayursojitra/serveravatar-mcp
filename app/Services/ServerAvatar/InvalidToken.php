<?php

namespace App\Services\ServerAvatar;

use RuntimeException;

/** A token could not be saved or checked. The message is safe to show the user; it never holds the token. */
final class InvalidToken extends RuntimeException {}
