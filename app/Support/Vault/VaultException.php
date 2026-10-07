<?php

namespace App\Support\Vault;

use RuntimeException;

/**
 * Something went wrong with key material.
 *
 * Messages here are deliberately vague about *why*. A decrypt that fails
 * because the context was wrong and one that fails because the ciphertext was
 * tampered with must be indistinguishable to a caller — telling them apart is
 * a padding-oracle in slow motion.
 */
class VaultException extends RuntimeException {}
