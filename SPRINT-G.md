# Sprint G — diagnostics, i18n, README & CI (v1.0)

> **Status: delivered.** Shipped in v1.0 — the last tracked sprint. See ROADMAP
> "Done: v1.0". No schema change (`DB_VERSION` stays 4). 290 tests (2 skip
> without GD), phpcs clean.

## What shipped

- **`Support\Diagnostic`** (VO) — status (`ok` / `info` / `warn` / `error`),
  label and a plain-English detail, with `ok()/info()/warn()/error()` factories.
- **`Support\HealthReport`** — the read-model: self-contained checks over schema,
  the Telegram bot, the notifications heartbeat, the signup page, app icons and
  mail. Reads options and the database only — **no outbound calls**, so the page
  can't hang on Telegram.
- **`Admin\DiagnosticsPage`** + `templates/admin/diagnostics.php` — a status
  table under EventCrew → Diagnostics, plus an environment footer (plugin / WP /
  PHP versions). Read-only; every fix it points at lives on Settings.
- **i18n** — `Domain Path: /languages` header + `load_plugin_textdomain` on
  `init`; `tools/make-pot.php` extracts `languages/eventcrew.pot` (~343 strings,
  translator hints and references carried) with a token-based scanner, since the
  dev box has no WP-CLI.
- **README** — rewritten from its v0.2-era state to the real 1.0 feature set,
  install and configuration.
- **CI** — `.github/workflows/ci.yml`: `composer lint` + `composer test` + a
  packaging build on PHP 8.2 and 8.3, every push.
- **Packaging** — `build-zip` now ships `README.md` and `languages/`.

## Backlog after this

Nothing tracked. What remains is host-side verification (see ROADMAP
"Verification owed after v1.0"): webhook reachability, scripted concurrency, the
notification cron across two runs, and one real bulk-mail delivery. The `tools/`
verification kit stays bundled until those are done.

## Verify

`phpunit --no-coverage` + `phpcs` clean; `php tools/make-pot.php` regenerates the
POT; `build-zip` → `eventcrew-1.0.0.zip`; no migration. See `tools/VERIFY.md`'s
v1.0 note: open EventCrew → Diagnostics and confirm each row reads sensibly.
