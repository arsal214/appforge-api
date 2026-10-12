<?php

/**
 * The panel and this API are on different origins, so CORS is load-bearing
 * rather than a formality.
 *
 * `allowed_origins` is an allowlist read from the environment, never `*`.
 * Wildcard origins would let any page on the internet make authenticated
 * requests on a visitor's behalf — the browser would refuse to *read* the
 * response only because `supports_credentials` is false, and that is far too
 * thin a thread to hang tenant data on.
 *
 * Sanctum here is token auth, not cookie auth: the panel sends
 * `Authorization: Bearer`, so no CSRF cookie route and no credentialed
 * requests are needed.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Bearer tokens, not cookies. Nothing here needs credentialed CORS.
    'supports_credentials' => false,

];
