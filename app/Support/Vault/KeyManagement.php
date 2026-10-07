<?php

namespace App\Support\Vault;

/**
 * Wrapping and unwrapping data keys — the outer half of envelope encryption.
 *
 * **Why not Laravel's `encrypted` cast?** It uses `APP_KEY`: one key for the
 * whole application, sitting in the same environment as the web process. That
 * gives us none of the three properties this data needs.
 *
 *  1. **Blast radius.** One data key per tenant means a leaked key exposes one
 *     customer, not every customer.
 *  2. **Crypto-shredding.** Destroying a tenant's wrapped data key makes their
 *     credentials unrecoverable in one operation — which is what "delete my
 *     data" has to mean when backups exist.
 *  3. **The master key is not ours to hold.** With a real KMS the plaintext
 *     master key never enters this process. Someone who reads our database, our
 *     environment and our memory still cannot decrypt anything without also
 *     holding an IAM identity that KMS will answer.
 *
 * `APP_KEY` rotation would also mean re-encrypting every row. Rewrapping data
 * keys does not touch the ciphertext.
 *
 * The `context` is authenticated but not encrypted, and it is bound to the
 * ciphertext. It is what stops a ciphertext lifted from one row being pasted
 * into another and decrypting successfully — the classic substitution attack
 * that encryption alone does not prevent.
 */
interface KeyManagement
{
    /**
     * A fresh data key: the plaintext to encrypt with, and the wrapped form to
     * store. The plaintext must be discarded as soon as it has been used.
     *
     * @param  array<string, string>  $context
     */
    public function generateDataKey(array $context): DataKey;

    /**
     * @param  array<string, string>  $context  must match the context used to wrap
     *
     * @throws VaultException when the context does not match, or the wrapped key
     *                        has been tampered with
     */
    public function unwrapDataKey(string $wrapped, array $context): string;

    /**
     * A short machine identifier — `local`, `aws-kms`. Stored beside every
     * ciphertext so a migration between drivers can tell what it is looking at
     * instead of guessing.
     */
    public function id(): string;

    /** Shown in the panel so an operator knows which key protects their data. */
    public function describe(): string;

    /** False for anything that must never guard a real customer's credentials. */
    public function isProductionGrade(): bool;
}
