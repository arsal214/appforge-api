# The credential vault

This directory holds other people's shop keys. Read this before changing
anything in it.

## What it protects

A customer's WooCommerce keys, Shopify token, APNs key and FCM service account.
Losing these is not "an incident" — it is other people's businesses. A stolen
WooCommerce key can read orders and customer addresses; a stolen APNs key can
send notifications to their customers in their name.

## How it works

Envelope encryption:

1. A **data key** is generated per credential, per write.
2. The secret is encrypted with that data key using **AES-256-GCM**.
3. The data key is **wrapped** by a master key and stored beside the ciphertext.
4. Decrypting unwraps the data key, uses it once, and drops it.

### Why not Laravel's `encrypted` cast

`APP_KEY` is one key for the whole application, sitting in the same environment
as the web process. That gives up all three properties this data needs:

- **Blast radius.** One data key per credential means a leaked key exposes one
  credential, not every customer's.
- **Crypto-shredding.** Destroying a wrapped data key makes its ciphertext
  unrecoverable in one operation — which is what "delete my keys" has to mean
  when backups exist.
- **Key custody.** With a real KMS the master key never enters this process.
  Someone who reads the database, the environment *and* process memory still
  cannot decrypt without an IAM identity KMS will answer.

Rotating `APP_KEY` would also mean re-encrypting every row. Rewrapping data keys
does not touch ciphertext.

### The context binding

Every ciphertext is bound to `{org, app, credential, type}` as GCM additional
authenticated data. This closes the **substitution attack** that encryption
alone leaves open: lifting a ciphertext from one row into another you control
produces a decrypt failure, not someone else's secret. There is a test for it.

### Why GCM

It authenticates as well as encrypts. A tampered ciphertext fails loudly instead
of decrypting to garbage that a later line of code then trusts. The IV is random
per message and never reused — reusing a GCM nonce under the same key does not
weaken it, it breaks it.

## Rules

**There is one way in and one way out.** `CredentialVault::put()` and
`CredentialVault::reveal()`. No Eloquent cast decrypts, no accessor unwraps, no
helper "just gets the token". The moment a second path exists, one of them stops
being audited and nobody notices for a year.

**Every reveal is audited, including failures.** A write is visible in the
panel; a read is invisible unless it is written down. A *failed* reveal is the
more interesting record — it is what an attack looks like.

**Every reveal states a purpose.** Six months later, reviewing a log,
`proxy: catalogue fetch` is the difference between an answer and a shrug.

**No endpoint returns a secret.** Not one. The panel shows that a key is set,
its last four characters, and when it was last used. A reveal button is a
screenshot, a read-it-out-to-me support call, and a browser-history entry
waiting to happen.

## What is missing, and why

### The production key manager

`LocalKeyManagement` holds the master key in the environment. It is correct
about structure and wrong about custody: anyone who can read the server can
decrypt everything. `VaultServiceProvider` **refuses to boot it in production**,
and the panel shows a red warning telling customers not to paste live keys.

The AWS KMS driver is **deliberately not written**. An untested KMS client is
worse than an absent one, because it looks finished and the first time anyone
finds out otherwise is with a customer's Shopify token in hand.

When it is written it must:

- Call `GenerateDataKey` with `KeySpec: AES_256` and the same encryption context
  this code already passes, so ciphertexts stay portable.
- Call `Decrypt` with that context, and fail closed if KMS rejects it.
- Use a per-environment CMK with a key policy that grants `Decrypt` **only** to
  the proxy and build-worker roles — never to the web tier.
- Enable automatic key rotation and CloudTrail on the CMK. KMS-side logging is
  the check on our own audit log.

Swapping it in changes one class. Nothing else in the vault moves.

### Process separation

`CredentialType` records where each secret may legitimately be decrypted:
`data` in the proxy, `build` in an ephemeral worker. **Neither should ever be
decrypted by the web process serving the panel**, and today everything runs in
one Laravel app, so that separation is recorded on each access rather than
enforced by deployment.

That is a real gap, not a documentation flourish. Before any live customer
credential is accepted:

- The proxy runs as its own deployment with its own IAM role.
- The build worker runs ephemerally and is destroyed after each build.
- The web tier's role has no `Decrypt` permission at all.

### Also outstanding

- **Cyber-liability insurance**, before a single real customer credential is
  stored. This was a condition from the start and it has not changed.
- **Key rotation on a schedule**, not just on replace.
- **Alerting on `reveal_failed`.** The record exists; nothing watches it.

## Tests

`tests/Feature/CredentialVaultTest.php` and `CredentialApiTest.php` are the
closest thing this repository has to a safety case. They cover the round trip,
that no plaintext reaches the database, context binding, tamper detection, fresh
keys per write, non-deterministic ciphertext, audit completeness, immutability of
the access log, crypto-shredding, and that no HTTP response anywhere contains a
secret.

If you change this code and those tests still pass, look again at whether you
have changed something they do not cover.
