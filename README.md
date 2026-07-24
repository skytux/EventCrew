# EventCrew

[![CI](https://github.com/skytux/EventCrew/actions/workflows/ci.yml/badge.svg)](https://github.com/skytux/EventCrew/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)

A WordPress plugin for **staffing, attendance and rewards at recurring events** —
run from a Telegram group and a public web page, administered from wp-admin.

Built for a dance event with three jobs (Decorate, Welcome, Clean) and free entry
earned by working, but the model is deliberately generic. A **Role** is a kind of
job, a **Task** is one instance of a role on a date needing N people, and an
**Assignment** is one person on one task. Nothing assumes the work is unpaid.

A person's identity is a **verified email**, never a WordPress account — so the
same person moves freely between the bot and the web page, and organizers are the
only ones who ever need a wp-admin login.

---

## What it does

- **Tasks & roles** (wp-admin) — define roles with capacities and optional time
  offsets, then generate a whole evening's tasks for an event in one click. A task
  set to **0 people** stays on the books but drops off the boards.
- **The Telegram bot** — a shared board in your group with a Join button per open
  task; private-chat onboarding that verifies an email; atomic, capacity-safe
  joins even under a double-tap; and a small, purposeful command set (below).
  Personal commands work from the group too — they answer in your DM and leave a
  short "📬 Sent you a DM" breadcrumb.
- **Public web signup** — an `[eventcrew_signup]` shortcode (and matching block)
  showing the same board, with email magic-link sign-in and claim/drop that obey
  exactly the same rules as the bot. Installable as a **mobile app** (PWA).
- **Roster & attendance** — mark who arrived / completed / no-showed, per person
  or a whole crew at once. This is also the **door list**: everyone in free
  tonight, workers plus credit-redeemers.
- **Reputation, credits & a leader role** — a recency-weighted read of each
  person's history (never a stored score), earned free-entry credits, an optional
  gate for at-risk members, and a trusted **crew leader** who can run a night
  without being an admin. All tunable in Settings.
- **Notifications on both channels** — every notice can reach a person by Telegram
  DM *and* email, each type toggleable per channel from a web tab or the
  `/notifications` menu. Signup confirmations and reminders always send.
- **Scheduled sends** — 24h task reminders and a 48h open-task call on an hourly
  WP-Cron heartbeat, with an opt-in fallback for hosts where WP-Cron never fires.
- **Diagnostics** (wp-admin) — a read-only health page: schema, bot, cron, signup
  page, icons and mail at a glance.

## Telegram commands

Everyone's commands answer in a private chat; asked in the group, the answer goes
to the DM with a breadcrumb left behind.

| Command | Who | What |
| --- | --- | --- |
| `/start` | anyone | Set yourself up (verify an email); also switches a stopped account back on |
| `/stop` | anyone | Switch your account off — no more email |
| `/me` | anyone | Your standing, credits and upcoming tasks |
| `/myhistory` | anyone | Everything you've done |
| `/mytickets` | anyone | Your door tickets, upcoming and past |
| `/ticket` | anyone | Spend a free-entry credit for a ticket |
| `/replace` | anyone | Cover someone else's task |
| `/notifications` | anyone | Choose which notices reach you, and on which channel |
| `/board` | group | Post or refresh the board of open tasks |
| `/roster` | organizers & the night's crew | The attendance roster; leaders can mark from it |
| `/gift` | organizers | Give someone free-entry credit(s) |
| `/allow` | organizers | Grant **leader** / a **one-time at-risk pass** / **admin** |
| `/leaders` | organizers | Who is eligible and who has been allowed to lead |

The board itself carries a Join/leave button per task, plus one-tap deep links to
onboard a newcomer or open a member's summary.

## Reputation, credits & leadership

- **Standing** is a recency-weighted average of a person's finished outcomes
  (completed, replaced, late-cancelled, no-showed), rated once they have enough
  finished tasks. It is never stored — always recomputed — so it can't drift. The
  per-outcome weights, the good-standing threshold, the recency half-life and the
  "rated after N tasks" bar are all editable in **Settings → Reputation**.
- An optional **sign-up gate** holds an at-risk member back from claiming new
  tasks; an organizer can wave one person through once with a **one-time pass**.
- **Credits** buy free entry, earned at one per N completed tasks (N configurable).
  A member spends their own (`/ticket` or the web profile) and an organizer can
  hand them out (`/gift`, the People page, or the door list) — every grant tells
  the recipient, and there's an audit log on the People screen.
- **Crew leaders** are a reserved, auto-managed role: when enabled for an event
  (globally by default, per-event on the Roster page), a 🧭 Leader slot appears on
  the board for crew the organizers have cleared with `/allow`. Its holder marks
  that night's crew from Telegram, no wp-admin needed. A **Leadership** admin page
  and `/leaders` show who has earned eligibility (completed enough of every role),
  and organizers are alerted when someone newly qualifies.

## Notifications

Every person-facing notice — confirmations, reminders, standing hits, credit
grants, ticket links, permission changes, cover arrangements, open-task and
freed-slot calls — can go out by **Telegram DM and email**. Each *convenience*
type has an independent DM and email toggle, set from the **Notifications** tab on
the web profile or the `/notifications` keyboard menu (the two share one store).
Signup/cancellation confirmations and task reminders are always sent; disabling
the account is the hard "no email at all" switch.

## Requirements

- WordPress 6.8+
- PHP 8.2+
- MySQL or MariaDB with InnoDB (the plugin converts its own tables if the host
  defaults to MyISAM)
- HTTPS with a valid certificate — required by Telegram for the bot webhook and by
  browsers for the installable app. Telegram refuses self-signed or plain-HTTP
  endpoints.

## Install

1. Build the distributable zip:

   ```
   php tools/build-zip.php
   ```

   This writes `dist/eventcrew-<version>.zip` containing a single `eventcrew/`
   directory — no Composer tree, no test suite. The plugin autoloads its own
   classes, so there is no `vendor/` to ship.

2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, install and
   activate. Activation creates the custom tables; the same migration also runs on
   any request where the stored schema version is behind, so an update dropped in
   over FTP (which skips the activation hook) is safe.

Then work through [`tools/VERIFY.md`](tools/VERIFY.md) to confirm the install on
the real host.

## Configure

Everything is under the **EventCrew** admin menu. Settings lists only controls the
shipped code actually reads, so there are no dead switches.

- **Settings → Telegram** — paste the BotFather token, save, *Install / refresh
  webhook*, then add the bot to your group. On hosts that block `/wp-json`, tick
  the admin-ajax fallback; where DNS is filtered, tick the DoH bypass. Re-run the
  webhook install after upgrades so new commands appear in the bot menu. The live
  webhook status is shown on the page.
- **Settings → Reputation** — outcome weights, the good-standing threshold, the
  sign-up gate, tasks-per-credit, "rated after" and the recency half-life.
- **Settings → Leadership** — whether events carry a leader slot by default, and
  the per-role experience bar for eligibility.
- **Settings → Notifications** — enable the on-request cron fallback if your host
  never fires WP-Cron. The next/last run times are shown.
- **Settings → Mobile app** — pick the page holding `[eventcrew_signup]`, and
  optionally an app name and theme colour.

The **Diagnostics** page summarises all of the above and flags anything that still
needs attention.

## Relationship to EventMesh

EventCrew works standalone. If the companion **EventMesh** plugin is installed, a
task can be pointed at a synced `eventmesh_event` post and will use that post's
title, and a new event's tasks can be created automatically on sync. Otherwise a
task carries its own typed event label. The link is one-directional — EventCrew
may know about EventMesh, never the reverse — with no hard dependency either way.

## Data and privacy

Records about people are personal data, so two behaviours are deliberate:

- **Uninstalling keeps everything by default.** Tables are dropped only when
  `eventcrew_delete_data_on_uninstall` is set, so removing the plugin does not
  destroy a season of attendance history; a reinstall picks up where it left off.
- **Identity is a verified email, never a WordPress user.** Email is transactional
  and switched off by disabling the account — `/stop` in the bot, or the one-click
  link in every message. A signed self-service page offers account disable and a
  full GDPR delete; deleting a person clears their assignments and redemptions too.

## Development

Composer is dev-only — the shipped plugin uses a hand-rolled autoloader and
carries no `vendor/` directory.

```
composer install
composer test    # PHPUnit + Brain Monkey — no WordPress or database required
composer lint    # PHP_CodeSniffer, PSR-12 plus the WPCS security sniffs
```

The suite fakes `$wpdb` rather than talking to MySQL, so it covers decision logic
and query shape. Anything depending on real SQL semantics — notably the
conditional insert that enforces task capacity under concurrent signups — is
covered by the end-to-end scripts in `tools/` (`verify-install.php`,
`concurrency-check.php`), run on the real host. CI runs `test` and `lint` on every
push ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

[ROADMAP.md](ROADMAP.md) holds the settled architectural decisions and the full
release history.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
