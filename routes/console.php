<?php

use Illuminate\Support\Facades\Schedule;

// FR-18: keep activity rows for the configured number of days (default 90).
Schedule::command('activity:prune')->daily();

// Open client registration leaves clients that never became a connection: remove them daily, and let
// Passport clear expired and revoked tokens and codes.
Schedule::command('oauth:prune-clients')->daily();
Schedule::command('passport:purge')->daily();
