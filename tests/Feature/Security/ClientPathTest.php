<?php

use App\Services\ServerAvatar\Client;
use Illuminate\Support\Facades\Http;

it('refuses a supervisor action that is not start, stop or restart, before any request is made', function (string $action) {
    Http::preventStrayRequests();

    expect(fn () => (new Client('token'))->controlSupervisor(1, 2, 3, 4, $action))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['../../servers', 'restart/../../x', 'delete', '', 'RESTART']);
