# AppForge — get it running

For a developer who has just been given access and wants the thing on screen.
Follow it top to bottom and do not skip step 1.

Every command here was run against a fresh clone on a clean folder on
**17 November 2026**, and the numbers in it are what came back. If something
does not work, the troubleshooting table at the end very likely names it.

This file lives in the `appforge-api` repo, so it is the one page you can
read on GitHub before cloning anything.

---

## 0. What you need first

| | |
|---|---|
| **PHP 8.3** + **Composer** + **MySQL 8** | all three come with [Laragon](https://laragon.org) on Windows |
| **Node 22 or newer** | `node -v`. Verified on 24. Not 20 — the browser
walk in the last section uses a WebSocket that Node 22 added |
| **Git access** | you must be able to `git clone` the five repos |

Open `C:\laragon\laragon.exe` and press **Start All** before anything else.
**MySQL does not survive a reboot** — if commands later hang with no output at
all, this is why.

---

## 1. Clone all five into one folder — the names matter

This is the step people get wrong, and nothing works afterwards if it is wrong.

`appforge-web` reaches into the other two repos by relative path:

```
"@appforge/components": "file:../appforge-shell/packages/components"
"@appforge/schema":     "file:../appforge-schema"
```

So the five folders must be **siblings**, with **exactly these names**:

```bash
mkdir appforge
cd appforge

git clone https://github.com/arsal214/appforge-schema.git
git clone https://github.com/arsal214/appforge-api.git
git clone https://github.com/arsal214/appforge-admin.git
git clone https://github.com/arsal214/appforge-web.git
git clone https://github.com/arsal214/appforge-shell.git
```

The repos are private, so first: **the owner must add your GitHub account as a
collaborator**, or every clone above fails with `repository not found` — which
is what it says whether the repo is missing or you simply cannot see it.

Over HTTPS, git asks for your username and a
[personal access token](https://github.com/settings/tokens) as the password;
your account password will not work. If you have an SSH key uploaded already,
`git@github.com:arsal214/<repo>.git` is the same clone without the prompting.

You should end up with:

```
appforge/
  appforge-admin/
  appforge-api/
  appforge-schema/
  appforge-shell/
  appforge-web/
```

If you renamed a folder, cloned them into separate places, or nested them,
`npm install` in `appforge-web` will fail. Move them and start again.

---

## 2. Make the two databases

Once, in a terminal:

```bash
mysql -u root -e "CREATE DATABASE appforge; CREATE DATABASE appforge_testing;"
```

Two, not one. The second is wiped and rebuilt every time the tests run, which
is why the tests never touch your real data.

---

## 3. The backend — `appforge-api`

**Copy the env file before installing.** With no `.env`, Laravel assumes it is
in production and the app deliberately refuses to boot, because the default
mailer would swallow order emails silently. You get a wall of red from
`composer install` and nothing explains it.

```bash
cd appforge-api

cp .env.example .env          # do this FIRST
composer install              # about 3 minutes
php artisan key:generate
php artisan migrate
```

One more, or the app crashes the first time anyone opens a customer's push
credentials — which is long after setup looks finished:

```bash
php artisan appforge:vault-key
```

It prints a `VAULT_LOCAL_KEY=...` line. Paste that into `.env`, replacing the
empty one already there. Development only; production uses a KMS and refuses to
start without it.

Then start it, and leave it running:

```bash
php artisan serve             # http://127.0.0.1:8000
```

Check it: open <http://127.0.0.1:8000/api/templates>. You should get JSON.

---

## 4. The panel — `appforge-web`

Install the two repos it depends on first, in this order.

```bash
cd ../appforge-schema
npm install

cd ../appforge-shell
npm install                   # about 1 minute

cd ../appforge-web
npm install                   # about 2 minutes

cp .env.example .env.local
npm run dev                   # http://localhost:3000
```

Open <http://localhost:3000>. The marketing site loads; `/login` and
`/register` work.

**It also runs with no `.env.local` at all** — it falls back to a mock backend
kept in your browser. Good for looking around, but nothing is saved to the
database. Copy the file when you want the real thing.

> Port 3000 must be free. If Next moves you to 3001, every API call is blocked,
> because the API only trusts `http://localhost:3000` exactly.

---

## 5. The staff panel — `appforge-admin`

```bash
cd ../appforge-admin

cp .env.example .env          # again, before installing
composer install
php artisan key:generate
php artisan appforge:make-admin   # asks for your name, email and password
php artisan serve --port=8001
```

Open <http://127.0.0.1:8001/admin> and sign in with what you just typed.
There is no sign-up page — that command is the only way in.

**Never run `php artisan migrate` here.** This repo owns no migrations;
`appforge-api` owns the whole database. A test fails the build if a
`database/migrations` folder ever appears.

---

## 6. The mobile app — `appforge-shell`

Already installed in step 4.

```bash
cd ../appforge-shell
npm start
```

Install **Expo Go** on your phone and scan the QR code. Your phone and your
computer must be on the same Wi-Fi.

---

## You are done when

Four things answer:

| | |
|---|---|
| <http://localhost:3000> | the marketing site |
| <http://localhost:3000/login> | the customer panel |
| <http://127.0.0.1:8000/api/templates> | JSON from the API |
| <http://127.0.0.1:8001/admin> | the staff login |

---

## Check everything is healthy

```bash
cd appforge-api    && php artisan test --testsuite=Feature   # 470 pass
cd ../appforge-admin && php artisan test --testsuite=Feature # 48 pass
cd ../appforge-web   && npm test                             # 575 pass
cd ../appforge-shell && npm test                             # 46 + 40 pass
cd ../appforge-schema && npm test                            # 14 pass
```

There is also a walk through the whole product in a real browser:

```bash
cd appforge-web
# stop `npm run dev` first, then:
NEXT_PUBLIC_API_MODE=mock npx next dev -p 3000
# and in another terminal:
npm run journey                                              # 21 ok
```

It refuses to run against the real API, because it signs up and creates an app.

---

## When something does not work

| What you see | What it is |
|---|---|
| `composer install` ends in red, `MAIL_MAILER is [log]` | no `.env` yet. `cp .env.example .env`, then run it again |
| Tests hang with no output at all | MySQL is not running. Open `laragon.exe` → Start All |
| `npm install` fails in `appforge-web` on `@appforge/components` | the five folders are not siblings, or one was renamed. See step 1 |
| `Unknown database 'appforge'` | step 2 was skipped |
| Panel loads but nothing saves | no `.env.local`, so it is on the browser-only mock backend |
| Every API call fails in the browser | Next is on port 3001. Free port 3000 and restart |
| `npm run build` fails on `NEXT_PUBLIC_SITE_URL` | expected without `.env.local`. `npm run dev` does not need it |
| `php` is not recognised | reopen the terminal after installing Laragon |
| Cannot sign in to `/admin` | run `php artisan appforge:make-admin` |
| An emailed link never arrives | nothing sends mail on a dev machine unless `php artisan schedule:work` is running in a spare terminal |

---

## What was actually checked

A clean clone of all five repos into an empty folder, then every command above
in order. `composer install` failed exactly as described until `.env` existed.
All three servers answered: the API on `/api/templates`, the admin login page,
and the panel's home, login and register pages — the last with **no
`.env.local` present at all**.

Not checked: macOS and Linux (every path here is Windows and Laragon), the
mobile app on a real phone, and cloning as a brand-new collaborator over HTTPS
— the clone above was verified with an SSH key already in place.
