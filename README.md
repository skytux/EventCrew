# EventCrew

Staffing, attendance and rewards for recurring events — driven from a Telegram
group and a public web page, administered from WordPress.

Built for a dance event with three roles (Decorate, Welcome, Clean) and free
entry earned by working — but the core vocabulary is deliberately generic. A
**Role** is a kind of job, a **Task** is one instance of a role on a date needing
N people, and an **Assignment** is one person on one task. Nothing in the model
assumes the work is unpaid.

## What it does

- **Tasks & roles** (wp-admin) — define roles with capacities and optional time
  offsets, then generate a whole evening's tasks for an event in one click.
- **The Telegram bot** — a board in your group with a Join button per open task;
  private-chat onboarding that verifies an email; atomic, capacity-safe joins;
  `/replace` to arrange cover; `/me` for standing and credits; `/ticket` to spend
  an earned credit on a free door ticket; `/roster` and `/gift` for organizers.
  Personal commands work from the group too — they answer in your DM and leave a
  short breadcrumb behind.
- **Public web signup** — an `[eventcrew_signup]` shortcode (and a matching
  block) showing the same board, with email magic-link sign-in and claim/drop
  that obey the same rules as the bot. Installable as a **mobile app** (PWA):
  add it to a phone home screen and it launches full-screen to the board.
- **Roster & attendance** — mark who arrived / completed / no-showed, per person
  or a whole crew at once. This is also the **door list**: everyone who gets in
  free tonight, workers plus credit-redeemers.
- **Reputation & credits** — a recency-weighted read of each person's history
  (never a stored score), rated on every finished task, with per-outcome weights
  and the good-standing threshold both editable in Settings. An optional join
  gate holds back at-risk members; free entry is earned at `floor(completed / 2)`
  credits, spendable by the member (`/ticket` or the web profile) or handed out
  by an organizer (`/gift` or the People page, which notify the recipient).
- **Scheduled notifications** — 24h task reminders and a 48h open-task call, sent
  by DM and email on an hourly WP-Cron heartbeat, with an opt-in on-request
  fallback for hosts where WP-Cron never fires.
- **Diagnostics** (wp-admin) — a read-only health page: schema, bot, cron, signup
  page, icons and mail at a glance.

## Requirements

- WordPress 6.8+
- PHP 8.2+
- MySQL or MariaDB with InnoDB (the plugin converts its own tables if the host
  defaults to MyISAM)
- HTTPS with a valid certificate — required by Telegram for the bot webhook, and
  by browsers for the installable app. Telegram refuses self-signed or plain
  HTTP endpoints.

## Install

1. Build the distributable zip:

   ```
   php tools/build-zip.php
   ```

   This writes `dist/eventcrew-<version>.zip` containing a single `eventcrew/`
   directory — no Composer tree, no test suite. The plugin autoloads its own
   classes, so there is no `vendor/` to ship.

2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, install
   and activate. Activation creates the custom tables; the same migration also
   runs on any request where the stored schema version is behind, so an update
   dropped in over FTP is safe.

After activating, work through `tools/VERIFY.md` to confirm the install on the
real host.

## Configure

Everything is under the **EventCrew** admin menu. Settings lists only controls
the shipped code actually reads, so there are no dead switches.

- **Settings → Telegram** — paste the BotFather token, save, then *Install /
  refresh webhook*, then add the bot to your group. On hosts that block
  `/wp-json`, tick the admin-ajax fallback; where DNS is filtered, tick the DoH
  bypass. The live webhook status is shown on the page.
- **Settings → Notifications** — enable the on-request cron fallback if your host
  never fires WP-Cron. The next/last run times are shown.
- **Settings → Reputation** — the standing threshold and the join gate.
- **Settings → Mobile app** — pick the page holding `[eventcrew_signup]`, and
  optionally an app name and theme colour.

The **Diagnostics** page summarises all of the above and flags anything that
still needs attention.

## Relationship to EventMesh

EventCrew works standalone. If [EventMesh](../wordpress-eventmesh) is also
installed, a task can be pointed at a synced `eventmesh_event` post and will use
that post's title; a new event's tasks can even be created automatically on sync.
Otherwise a task carries its own typed event label. The link is one-directional —
EventCrew may know about EventMesh, never the reverse — and there is no hard
dependency in either direction.

## Data and privacy

Records about people are personal data, so two behaviours are deliberate:

- **Uninstalling keeps everything by default.** Tables are dropped only when
  `eventcrew_delete_data_on_uninstall` is set, so removing the plugin does not
  destroy a season of attendance history. A reinstall picks up where it left off.
- **Identity is a verified email, never a WordPress user.** WP accounts belong to
  organizers only. Email is transactional and switched off by disabling the
  account — `/stop` in the bot, or the one-click link in every message. A signed
  self-service page offers account disable and a full GDPR delete, and deleting a
  person clears their assignments and redemptions too.

## Development

Composer is dev-only — the shipped plugin uses a hand-rolled autoloader and
carries no `vendor/` directory.

```
composer install
composer test    # PHPUnit + Brain Monkey, no WordPress or database required
composer lint    # PHP_CodeSniffer, PSR-12 plus the WPCS security sniffs
```

The suite fakes `$wpdb` rather than talking to MySQL, so it covers decision logic
and query shape. Anything depending on real SQL semantics — notably the
conditional insert that enforces task capacity under concurrent signups — is
covered by the end-to-end scripts in `tools/` (`verify-install.php`,
`concurrency-check.php`), run on the real host. CI runs `test` and `lint` on
every push (`.github/workflows/ci.yml`).

See the *Local dev environment* note in [ROADMAP.md](ROADMAP.md) for running PHP
with the right extensions on a machine without a global php.ini, along with the
settled architectural decisions and the release history.

Translations: `php tools/make-pot.php` regenerates `languages/eventcrew.pot` from
the source strings.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
