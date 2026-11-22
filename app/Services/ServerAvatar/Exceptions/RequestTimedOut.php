<?php

namespace App\Services\ServerAvatar\Exceptions;

/** A request that changes something got no answer in time. ServerAvatar may still have carried it out. */
final class RequestTimedOut extends ServerAvatarException {}
