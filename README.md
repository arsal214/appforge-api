# appforge-api

The customer-facing API. Laravel 13, MySQL, Sanctum token auth.

This repo **owns the database schema for the whole AppForge estate**.
`appforge-admin` reads and writes the same MySQL database and must never carry a
`database/migrations` directory. Two repos migrating one schema produces
divergence that nobody can bisect, in production, at the worst possible moment.

## Running it locally

```bash
cp .env.example .env
php artisan key:generate
# MySQL 8, database `appforge` — see .env
php artisan migrate
php artisan serve            # http://127.0.0.1:8000
```

Then in `appforge-web`, set `NEXT_PUBLIC_API_MODE=http` and
`NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api` (already the default in
`.env.example`).

## The contract lives in the panel

`appforge-web/src/lib/api/client.ts` and `types.ts` are the spec, not a
convenience. Every response is validated there with zod at the boundary, so a
shape change here surfaces as one loud parse error rather than an `undefined`
three components deep.

Two consequences that are easy to get wrong:

- **Keys are camelCase.** `organizationId`, `sourceUrl`, `accentColor`. That
  includes validation error keys — Laravel keys `errors` by field name and the
  panel maps those keys straight onto form inputs, so a snake_case rule orphans
  its own message. Form Requests therefore validate camelCase input.
- **Timestamps are `Z`-suffixed ISO 8601 with milliseconds**, produced by
  `App\Http\Resources\Iso8601`. Eloquent's default serialisation happens to
  satisfy zod today; formatting explicitly removes the dependency on that
  staying true.

Before you swap the panel back to `NEXT_PUBLIC_API_MODE=mock`, note that
`src/lib/api/mock/transport.ts` is a deliberate mirror of this API's behaviour,
including the 404-not-403 rule. If you change behaviour here, change it there.

## Tenancy — read this before writing a query

Another organisation's record is **404, not 403**. A 403 confirms the record
exists, which tells a competitor that a particular business is our customer.
The status code itself is the leak. `tests/Feature/TenancyTest.php` pins this
for `GET`, `PATCH` and `DELETE`.

There are two independent mechanisms and they are not redundant:

1. **Controllers query through the relationship** —
   `$request->user()->organization->apps()`. This is tenant-safe by
   construction and does not depend on ambient authentication state. It is the
   actual boundary.
2. **`OrganizationScope`** is a global scope on tenant-owned models. It exists
   to catch the query someone writes in six months that forgets mechanism 1.
   When there is no authenticated user it applies `1 = 0` rather than no
   filter, because "the scope quietly did nothing" is exactly the incident it
   is meant to prevent. Console and queue contexts are exempt (they
   legitimately have no user); **tests are not exempt**, so the fail-closed
   branch is exercised rather than left to rot.

`withoutGlobalScopes()` is banned in this layer. If you want it, you want a
query the customer is not entitled to make — write it in `appforge-admin`,
where crossing tenants is the explicit job and is audited.

## Roles — the other axis

Tenancy decides *whose* records you can reach. Roles decide what you may do
with your own organisation's, and they are a different mechanism with a
different status code: **403, not 404**. The record belongs to the caller;
pretending it is missing would send them hunting for a bug instead of asking
the owner for a different role.

| | owner | editor | staff |
|---|---|---|---|
| read apps, analytics and orders | yes | yes | yes |
| work orders — move one through its statuses | yes | yes | yes |
| build — configs, versions, credentials, push, profiles, delete an app | yes | yes | no |
| the team, and the organisation's own settings | yes | no | no |

The table lives once, in `User::ABILITIES`, and is applied by the `allow:`
middleware on the route. `appforge-web/src/lib/roles.ts` is a second copy used
only to decide which links are worth showing — if the two drift, the panel
offers a button that 403s, which is the direction that fails safely.

There is exactly one owner per organisation: the account that registered.
An invitation cannot create another, and the owner cannot be re-roled or
removed through this API, because the only safe version of that is an account
transfer with a human in the loop.

An invitation is a link the owner passes on themselves. Only its SHA-256 hash
is stored, so the link cannot be recovered from the database — reissuing it
replaces the invite. There is no mail pipeline in this product yet, and a
feature that silently depends on one is worse than one that admits it.

## Identifiers

Primary keys are prefixed ULIDs in `char(30)`: `app_01kypsqmjnk5yr520xrxem7g1e`.

Auto-increment integers would leak volume — a competitor can read your customer
count off an id — and invite enumeration. ULIDs keep integer-like index
locality. The three-character prefix means an id in a log line or a support
ticket says what it points at, and an id pasted into the wrong endpoint is
rejected by shape (`HasPrefixedUlid::isValidUniqueId`) before it reaches the
database.

## Configs: the draft, and the versions

`PUT /apps/{app}/config` stores the editor draft — one row per app, overwritten
on save. `POST /apps/{app}/versions` freezes it as an immutable numbered
snapshot. There is no route to edit or delete a version, and `AppVersion` has no
`updated_at` column, because a version is what a shell on a customer's phone
downloaded; if it could change underneath them, a rollback would have nothing
to roll back to.

**`ConfigValidator` is a security boundary, not a convenience.** Read its
header before touching it. The rules it enforces and why:

- **Unknown keys are rejected everywhere.** This is what keeps credentials out
  of configs. A config is served to an app binary anyone can unpack, so it is
  effectively public. An allowlist means neither a well-meaning future feature
  nor a hostile client can park an API key in one.
- **Actions are a closed vocabulary.** No expression language, no callback
  strings. A runtime that executes arbitrary strings from a server is remote
  code execution and an App Store rejection.
- **Every URL is http or https.** `javascript:` in an `openUrl` action is stored
  XSS in the panel preview, which renders the same components through
  react-native-web in a real browser.
- **Everything is bounded** — size, depth, counts, string lengths. Depth is
  checked first, via `json_decode`'s depth argument, because `[[[[…` is two
  bytes per level and PHP has no recursion limit.

Errors come back keyed by dotted path
(`config.screens.0.sections.2.props.title`) so the panel can highlight the
control that is wrong instead of saying "invalid".

Drafts are re-validated at publish time even though they were validated at
save time. Rules tighten; a draft written months ago against looser rules must
not reach a phone just because it was legal when it was typed.

`ConfigSchema` is a hand-maintained PHP mirror of `schema.ts`. **Change one,
change the other in the same commit.** Phase D replaces both with generated
code from `appforge-schema`; until then this duplication is the known cost of
enforcing the contract on the server, which is the only place enforcing it
means anything.

## Templates are code, not rows

`app/Support/Templates.php`. Each template's `industry` keys into
`templateConfigs` in `@appforge/components`, so a template only exists if the
renderer can render it. A `templates` table would let someone create a template
the shell has never heard of — which reaches a customer's phone as a blank
screen. Adding a template is a deploy because it *is* a deploy: the components
ship with it.

## Tests run against MySQL

`phpunit.xml` points at `appforge_testing`, not sqlite in memory. The tests
exist to prove the schema and the tenancy scoping behave; sqlite accepts things
MySQL rejects and treats foreign keys as optional, so a green suite against a
different engine than production is worth very little.

```sql
CREATE DATABASE appforge_testing
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan test
./vendor/bin/pint
```

One trap worth knowing: Laravel's `RequestGuard` memoises the user it resolved,
and the container survives between requests inside a single test. A test that
authenticates twice will silently replay the first request's identity. Call
`$this->forgetAuthentication()` between them — see `tests/TestCase.php`. Without
it, the token-revocation test passes whether or not revocation works.

## What is deliberately not here yet

- **The build pipeline.** Versions exist and are publishable, but nothing
  consumes them yet. `apps.published_version` stays null and `status` never
  reaches `live` through this API: putting an app in a store is a human
  process, and `ready` only means there is something publishable.
- **Optimistic concurrency on draft saves.** Two devices editing one app is
  currently last-write-wins. The fix is to accept the `updatedAt` the client
  loaded and reject a stale write; the panel already tracks the value, the
  server does not yet check it.
- **Store credentials.** When they land: KMS envelope encryption with
  per-tenant data keys, decrypted only inside the ephemeral build worker, never
  logged, fully audited. They are **never** part of an SDUI config — a config
  is effectively public and only ever carries a `credentialRef`.
- **Audit log.** Belongs with the first write that needs it rather than as an
  empty table nobody writes to.

## Class naming note

`App\Models\App` shadows the `App` root namespace inside `App\Models`. That is
deliberate — "app" is the domain word everywhere else (the table, the endpoint,
the panel's TypeScript type) and a synonym would cost more than it saves. Use
the `app()` and `config()` helpers rather than the `App` facade in that
namespace; `AppController` imports the model as `ClientApp` for the same reason.
