# EventCrew

Staffing, attendance and rewards for recurring events —
driven from a Telegram group, administered from WordPress.

Built for dance events: three roles on a given night, a couple of people
per role, and free entry earned by turning up.

## What it does

- **Tasks** — a date, a role (Decorate, Welcome, Clean by default), a
  capacity, optionally attached to an event.
- **People** — identified by a verified email address. Never WordPress
  users; WP accounts belong to organizers only.
- **Rewards** — completing a task earns free entry to that event, and every
  two completed tasks earn a credit redeemable for a future one.
- **Reputation** — a recency-weighted score over attendance history, gating
  credit redemption.

## Status

Under construction, one usable release at a time.

| Release | Contents | State |
|---|---|---|
| v0.1 | Schema and migrations, task and person management in wp-admin, roles | **done** |
| v0.2 | Vocabulary refactor (volunteer→person, shift→task, roles) | **done** |
| v0.3 | Telegram group bot: board, deep-link onboarding, email verification, join/leave | next |
| v0.4 | Roster and attendance marking | planned |
| v0.5 | Reputation, credits, redemption, door list | planned |
| v0.6 | Public signup page with magic-link self-service | planned |
| v0.7 | 24h reminders and the 48h open-task call | planned |
| v1.0 | Translation pass, packaging, CI | planned |

See [ROADMAP.md](ROADMAP.md) for the settled decisions, the v0.2 rename mapping,
and the end-to-end verification still owed.

Settings only ever lists controls the shipped code actually reads, so options
for reminders, reputation thresholds and bot credentials appear alongside the
releases that use them.

## Requirements

- WordPress 6.8+
- PHP 8.2+
- MySQL or MariaDB (the plugin creates its own tables)

For the Telegram bot, from v0.3: HTTPS with a valid certificate. Telegram
refuses to deliver webhooks to self-signed or plain HTTP endpoints.

## Relationship to EventMesh

EventCrew works standalone. If [EventMesh](../wordpress-eventmesh) is also
installed, a task can be pointed at a synced `eventmesh_event` post and will
use that post's title; otherwise it carries its own event label. There is no
hard dependency in either direction.

## Data and consent

Records about people are personal data, so two behaviours are deliberate:

- **Uninstalling keeps everything.** Tables are only dropped when
  `eventcrew_delete_data_on_uninstall` is set, so removing the plugin does not
  destroy a season of attendance history.
- **Open-task email is opt-in.** The consent timestamp and the surface it was
  given on are both recorded, because the burden of demonstrating consent falls
  on the site owner. An organizer cannot opt someone in on their behalf, and
  people added by hand in wp-admin start unverified and unsubscribed.

## Development

Composer is dev-only — the shipped plugin uses a hand-rolled autoloader and
carries no `vendor/` directory.

```
composer install
composer test    # PHPUnit + Brain Monkey, no WordPress or database required
composer lint    # PHP_CodeSniffer, PSR-12 plus the WPCS security sniffs
```

The test suite fakes `$wpdb` rather than talking to MySQL, so it covers
decision logic and query shape. Anything depending on real SQL semantics —
notably the conditional insert that enforces task capacity under concurrent
signups — needs the end-to-end checks described in the project plan.

## Licence

GPL-2.0. See [LICENSE](LICENSE).
