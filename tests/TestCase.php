<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeys();
    }

    /** Passport signs access tokens with these keys. They are generated once and never committed. */
    private function ensurePassportKeys(): void
    {
        if (! is_file(Passport::keyPath('oauth-private.key')) || ! is_file(Passport::keyPath('oauth-public.key'))) {
            Artisan::call('passport:keys', ['--force' => true]);
        }
    }
}
