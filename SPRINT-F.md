# Sprint F — installable mobile web app (PWA) (v0.10)

> **Status: delivered.** Shipped in v0.10. See ROADMAP "Done: v0.10". No schema
> change (`DB_VERSION` stays 4). 287 tests (2 skip without GD), phpcs clean.

## What shipped

- **`Support\WebManifest`** (pure) — the web-app-manifest builder:
  `display: standalone`, `start_url` on the signup page, 192/512/maskable icons.
- **`Support\AppIcon`** (GD) — draws a lettered icon in the theme colour when the
  site has no Site Icon; returns `''` (caller falls back) where GD is absent.
- **`Web\PwaController`** — serves the manifest, service worker and icons as
  ordinary front-end requests (`?eventcrew_pwa=…`, dodging the `/wp-json` block),
  and injects the install tags on the configured page. Icons prefer the
  WordPress **Site Icon** (`get_site_icon_url`) when set. The service worker is
  served from the site root so its scope covers the origin; it is a small
  network-first worker with a `fetch` handler (enough for installability). Head
  tags cover **Android** (manifest + SW prompt) and **iOS** (`apple-touch-icon` +
  `apple-mobile-web-app-*`).
- **Settings → Mobile app** — a `wp_dropdown_pages` signup-page picker
  (`eventcrew_signup_page_id`), app name (`eventcrew_app_name`, defaults to the
  site name) and theme colour (`eventcrew_app_theme_color`).
- **Bug fix** — the v0.9 signup page returned to the homepage after each action
  because it built its return URL with `add_query_arg([], null)` (empty URI); now
  `get_permalink()`.

## Explicit backlog after this (the last item)

- Diagnostics page, translation pass, README, packaging CI. → **v1.0**

## Verify

`phpunit --no-coverage` + `phpcs` clean; `build-zip` → `eventcrew-0.10.0.zip`; no
migration. See `tools/VERIFY.md`'s v0.10 note: pick the signup page in Settings →
Mobile app, open it on a phone, and install to the home screen.
