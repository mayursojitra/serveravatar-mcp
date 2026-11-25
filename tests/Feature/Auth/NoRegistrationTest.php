<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

// "No registration anywhere. Login only."

it('has no registration route, GET or POST', function (string $path) {
    $this->get($path)->assertNotFound();
    $this->post($path, ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Valid-Passw0rd-1'])->assertNotFound();
})->with(['/register', '/sign-up', '/signup']);

it('has no registration route even for a signed-in admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/register')->assertNotFound();
    $this->actingAs($admin)->post('/register')->assertNotFound();
});

it('does not define registration or email verification routes at all', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse();

    $this->get('/verify-email')->assertNotFound();
    $this->get('/confirm-password')->assertNotFound();
});

it('does not offer a sign-up link or flag on the login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login')->missing('canRegister'));
});

it('only the allowed guest routes exist', function () {
    $guestUris = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('guest', $route->middleware(), true))
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($guestUris)->toBe(['forgot-password', 'login', 'reset-password', 'reset-password/{token}', 'two-factor-challenge']);
});
