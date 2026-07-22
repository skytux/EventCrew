# Sprint E — public web signup & magic-link self-service (v0.9)

> **Status: delivered.** Shipped in v0.9. See ROADMAP "Done: v0.9". No schema
> change (`DB_VERSION` stays 4). 275 tests, phpcs clean.

## What shipped

- **`Support\SignupService`** — the one claim/drop rulebook (gate + overlap +
  capacity for `claim`, notice-classified `drop`), now shared by the bot and the
  web. `BoardService` delegates to it and keeps only its Telegram wording; the
  `eventcrew_reputation_gate` / `eventcrew_notice_hours` option constants moved
  here.
- **`Support\WebSession`** — a stateless, HMAC-signed ~30-day login cookie
  (no server state), plus a per-person CSRF token for the POST forms.
- **`Web\SignupController`** — the `[eventcrew_signup]` shortcode and a buildless
  dynamic block (shared PHP renderer), with `admin-ajax` `nopriv` handlers for
  sign-in, the magic-link target, claim, drop and sign-out. Email sign-in finds
  or creates the person, mails a single-use `auth_token` link that verifies the
  address and starts the session. Claiming goes through `SignupService`, so the
  web respects verification, capacity, overlap and the reputation gate exactly as
  the bot does.
- **`templates/public/signup.php`** — the rendered board with per-task
  Claim/Drop, an email sign-in form, the person's standing/credits, sign-out, and
  redirect-carried notices.
- **`blocks/signup/editor.js`** — the buildless block registration (uses core's
  `wp-blocks` / `wp-server-side-render`; front-end render is the PHP callback).
- Actions run over `admin-ajax.php` (not `/wp-json`, which the host blocks).
  Settings gains a read-only "Public signup page" note; `build-zip` now ships
  `blocks`.

## Explicit backlog after this (the last item)

- Diagnostics page, translation pass, README, packaging CI. → **v1.0**

## Verify

`phpunit --no-coverage` + `phpcs` clean; `build-zip` → `eventcrew-0.9.0.zip`
(now including `blocks`); no migration. See `tools/VERIFY.md`'s v0.9 note for the
place-the-shortcode / sign-in / claim / drop walk-through.
