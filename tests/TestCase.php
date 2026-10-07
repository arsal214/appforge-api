<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop the resolved auth guards so the next request re-reads its own
     * Authorization header.
     *
     * A test that makes two authenticated requests otherwise gets the user the
     * *first* one resolved, because RequestGuard memoises it and the container
     * survives between calls within a single test. That would make a token
     * revocation test pass whether or not revocation works, which is worse
     * than having no test at all.
     */
    protected function forgetAuthentication(): void
    {
        Auth::forgetGuards();
    }
}
