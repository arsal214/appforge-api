<?php

use App\Providers\AppServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\VaultServiceProvider;

return [
    VaultServiceProvider::class,
    AppServiceProvider::class,
    RateLimitServiceProvider::class,
];
