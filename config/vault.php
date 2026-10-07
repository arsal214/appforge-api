<?php

return [

    /*
    | Which key manager protects credentials.
    |
    | 'local' keeps the master key in this environment — fine for development,
    | refused in production by VaultServiceProvider. A 'kms' driver backed by
    | AWS KMS is what production needs and is not written yet, because a KMS
    | client that has never run against AWS looks finished and is not.
    */
    'driver' => env('VAULT_DRIVER', 'local'),

    /* 32 bytes, base64. Generate with: php artisan appforge:vault-key */
    'local_key' => env('VAULT_LOCAL_KEY', ''),

];
