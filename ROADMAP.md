# EventCrew — project brief and roadmap

Written so a cold reader (or a fresh session) can pick this up without the
originating conversation. For the *why* behind the architecture, see the
planning document at `~/.claude/plans/imperative-foraging-galaxy.md`.

## What this is

A WordPress plugin for staffing recurring events. An organizer defines what
needs doing on a given date; people claim those jobs from a Telegram group;
the plugin tracks who turned up and what they earned.

Built for a dance event with three roles (Decorate, Welcome, Clean), two
people on Decorate, free entry earned by working.

The core vocabulary is deliberately generic: a **Role** is a kind of job, a
**Task** is one instance of a role on a date needing N people, an
**Assignment** is one person on one task. Nothing in the model assumes the
work is unpaid.

## Settled decisions

Do not re-open these without new information.

| Decision | Reasoning |
|---|---|
| WordPress plugin, not a standalone PHP app | The shared host already runs WordPress, which supplies storage, an HTTPS endpoint, cron and an admin fallback. A standalone app rebuilds all four. |
| Sibling plugin, not an EventMesh module | The two share conventions, not machinery. Soft link only: a task may reference an `eventmesh_event` post, falling back to its own label. EventCrew must run standalone. |
| Custom tables, no CPTs | A join record makes an unusable CPT list table. All assignment access goes through a repository so this stays reversible. |
| People are never WordPress users | WP accounts are for organizers. Identity is a verified email; the Telegram id is an optional link on the row. |
| Email is opt-in, not opt-out | `email_opt_in_at` NULL by default; timestamp and source stored together as the consent record. An organizer cannot consent on someone's behalf. |
| The bot lives in the existing Telegram group | A bot cannot DM a user who hasn't started it, so the group carries the board and anything personal goes to private chat or a callback alert. |
| Settings lists only controls the shipped code reads | Options arrive with the release that uses them, rather than accumulating dead switches. |

## Where it stands

**v0.1 and v0.2 are committed**: schema and migrations, task and person
management in wp-admin, roles in Settings, and the vocabulary refactor below.
58 tests, phpcs clean.

**Verified on a real install.** 103 checks passed on WordPress 7.0.2 /
MariaDB 11.4 / PHP 8.3. The conditional insert parses and enforces capacity;
every repository statement runs clean; teardown returned the row counts to
baseline. One failure — the host created the tables as MyISAM — and one skip,
the concurrency phase, for want of WP-CLI.

**v0.3 then grew.** What the verification unblocked was a round of schema and
admin work that all had to land *before* real data existed: InnoDB, datetimes,
role archiving, event linking, templates. The Telegram bot moved to v0.4 as a
result. That is the ordering principle this project keeps rediscovering, and
it keeps being right.

---

## Done: v0.2 — the vocabulary refactor

Landed before any install, which was the whole point.

### Why the ordering mattered

The rename touched table and column names. Because no database anywhere held
these tables yet, it cost a find-and-replace and a rewrite of
`Schema::statements()` — `DB_VERSION` did not even move, since there was no
deployed schema to migrate *from*.

Had v0.1 been activated on the live site first, the identical change would have
been a data migration: create new tables, copy rows, remap foreign keys, drop
the old ones, and get it right on a production database holding real signup
history. Same diff, entirely different risk, purely as a function of ordering.

### Why rename at all

The domain is event organizing, not volunteering. "Volunteer" and "shift"
encode one particular use of the tool — unpaid help on a rota — into the type
names. Paid staff, performers, a committee rota, a conference's session hosts
are all the same shape, and the current vocabulary quietly excludes them. The
reward system stays volunteer-flavoured by design; the *core* need not be.

### The mapping

| Was | Is now |
|---|---|
| `Volunteer` | `Person` |
| `VolunteerRepository` | `PersonRepository` |
| `VolunteersPage`, `VolunteersListTable` | `PeoplePage`, `PeopleListTable` |
| `eventcrew_volunteers` | `eventcrew_people` |
| `volunteer_id` | `person_id` |
| `Shift` | `Task` |
| `ShiftRepository` | `TaskRepository` |
| `ShiftsPage`, `ShiftsListTable` | `TasksPage`, `TasksListTable` |
| `eventcrew_shifts` | `eventcrew_tasks` |
| `shift_id` | `task_id` |
| `shift_date` | `task_date` (in both `tasks` and `notifications`) |
| `TaskTypes` | `Roles` |
| `task_slug` column | `role_slug` |
| `eventcrew_task_types` option | `eventcrew_roles` |
| `Assignment`, `AssignmentStatus` | unchanged — already generic |

Method and string renames followed: `volunteerIdsAssignedOn` →
`personIdsAssignedOn`, `acceptsOpenShiftEmail` → `acceptsOpenTaskEmail`,
`Shift::taskLabel()` → `Task::roleLabel()`, `hasOpenSlotsOn` unchanged, and the
user-facing "open-shift call" became the "open-task call".

### The one naming conflict, and how it resolved

`Shift` → `Task` collided with `TaskTypes`, which named the groups (Decorate,
Welcome, Clean). Two options were weighed:

- **Keep `TaskType`** — `Task` and `TaskType` is a standard, unambiguous
  pairing. Initially recommended.
- **`Role` for the group** — reads better ("assign people to tasks by role").
  Initially argued against on the grounds that it collides with WordPress's own
  role/capability vocabulary (`get_role()`, `add_role()`). **Chosen**, because
  that objection does not survive contact with the codebase: everything here is
  namespaced classes, so `EventCrew\Support\Roles` cannot collide with global
  WordPress functions, and the only cost was a readability worry that the
  clearer domain word more than repays.

So: a **Role** is a kind of job; a **Task** is one instance of a role on a date
needing a given number of people; an **Assignment** is one person on one task.

### Scope guard, and whether it held

A rename, not a redesign — no behaviour changes, no schema shape changes beyond
identifiers, no new features. The check was that the suite stay green with the
same test count and only identifiers changed.

It held: 54 tests, 114 assertions before and after, with no assertion rewritten.

Four tests were then *added* — `CodebaseStructureTest` — because the refactor
exposed a real blind spot rather than a bug. The plugin autoloads by turning a
class name straight into a filename and renders templates by bare slug, so a
class left in a mismatched file, or a page pointing at a renamed template,
fails at runtime with a fatal or a `wp_die` and no test would notice. Those
four assert class/file agreement, namespace/directory agreement, that every
rendered template exists, and that the retired vocabulary cannot creep back.

### Open question, deliberately not answered yet

With people and tasks generic, the **reward model** is the remaining
volunteer-specific concept: completing tasks earns free entry. Whether that
becomes optional, pluggable, or simply stays as the one opinionated feature is
worth deciding when v0.5 is built — not now. Renaming it speculatively would
be designing for a user who does not exist.

---

## Release ladder

| Release | Contents |
|---|---|
| v0.1 | ✅ Schema, migrations, wp-admin CRUD, roles |
| v0.2 | ✅ Vocabulary refactor |
| v0.3 | ✅ Verified on a real install; InnoDB, task datetimes, role archiving, EventMesh event picker, role templates |
| v0.3.1 | ✅ EventMesh fires `eventmesh/event_synced`; EventCrew optionally auto-creates a new event's tasks. (EventMesh: Holvi timezone fix.) |
| v0.4 | ✅ The Telegram group bot: board, deep-link onboarding, email verification, atomic join/leave, multi-event board. Concurrency now scriptable over the webhook. |
| v0.5 | ✅ Roster and attendance marking in wp-admin, plus a read-only `/roster` for organizers over the bot |
| v0.6 | Reputation, credits, redemption, door list |
| v0.7 | Public signup page, magic-link self-service |
| v0.8 | 24h reminders and the 48h open-task call, cron + loopback-free fallback |
| v1.0 | Translation pass, README, packaging script, CI |

## Done: v0.3 — verification, then the schema it exposed

### What the install told us

The kit found one real defect and confirmed the thing most likely to be
broken. `AssignmentRepository::join()` wraps its occupancy subquery in a
derived table to get around MySQL's refusal to read the insert target
directly; a faked `$wpdb` cannot tell you whether that parses, and if it did
not, every join would have silently returned `full`. It parses, and capacity
holds.

The defect: **every table came up MyISAM.** dbDelta never states an engine, so
it inherits the host's `default_storage_engine`, and every column and index
matched the declaration in the process — nothing short of asking the engine
directly would have caught it.

Worth being precise about why that mattered, because the obvious reason is
wrong. MyISAM does **not** endanger the capacity guard: its table-level write
lock serialises the conditional insert at least as firmly as InnoDB's row
locking. It matters because MyISAM has no crash recovery — an unclean shutdown
corrupts the table holding the signup and attendance history — and no
transactions, which puts v0.5's "spend a credit, write the redemption"
permanently out of reach. So `ENGINE=InnoDB` is now declared in every
`CREATE TABLE`, and `Schema::ensureInnoDb()` converts what already exists,
because dbDelta compares columns and keys but never the engine.

### Then: the schema changes that had to precede real data

| Change | Why now rather than later |
|---|---|
| `starts_at`/`ends_at` `time` → `datetime` | A bare time cannot express a task running past midnight, which every clean-up after an evening event does. Widening an empty column is free; widening one holding a season of tasks is not. |
| `task_date` stays a `date` | It is the day a task is *filed under*, not the day it starts. A 01:00 Sunday clean-up belongs on Saturday's board, reminder and open-task call. Deriving the day from `starts_at` would have put it on the wrong one. |
| Roles archived, never deleted | A task stores a role slug. Deleting the role leaves the roster reading `decorate` instead of `🎈 Decorate`, and strips the meaning from credits earned under it. |

The overlap query got **more** correct as a side effect: it no longer requires
two tasks to share a `task_date`. While the columns held bare times that
equality was load-bearing, because comparing times across days is meaningless.
Now it was actively wrong — it would have missed a Saturday task ending 01:00
Sunday clashing with a Sunday task starting 00:30, which is the exact case the
check exists for.

### Event linking and templates

The old form asked for a raw post ID in a number field. It now leads with an
event picker — `eventmesh_event` posts, resolved through the same
manual-overrides-scraped rule EventMesh applies internally — and choosing one
fills in the date and both times. "Other" reveals a free-text name.

`Support\EventSource` is the whole of the soft link and names no EventMesh
class, so a standalone install simply gets no picker and the typed name. It
does duplicate EventMesh's `EventMeta::resolve()` rule, deliberately: those
meta keys are registered post meta and a stable contract, and the settled
decision is a soft link only. It converts DATE_ATOM to the site's timezone
rather than truncating the string, which would displace every event by its
offset.

Roles carry an optional **anchor plus two offsets in minutes**: decorate
`-120`→`0` from the start, clean `0`→`+60` from the end. That third case is
why the anchor exists — it is the task that crosses midnight. "Create an
event's tasks" then builds a whole evening in one click, skipping roles that
already have a task for that event so it is safe to re-run after adding one.
Roles with no offsets produce untimed tasks, which is what the organizer used
to get by leaving the time fields blank.

`Support\TaskTemplate` holds all of that as a pure function — no database, no
options, no clock — because every interesting scheduling decision lives there
and none of it should need a MySQL to test.

### Still owed from v0.3 — settled in v0.4

The concurrency phase never ran: the host has no WP-CLI, and there is no way
to get two PHP processes into the conditional insert at once without one. v0.4
delivers the other way in — `tools/concurrency-check.php` fires six
`callback_query` joins at the live webhook with `curl_multi`, needing no shell,
only the installed bot. It stays a host-run script (there is still no local WP
here), but it now exists and is packaged.

---

## Done: v0.3.1 — EventMesh fires, EventCrew listens

The manual "Create an event's tasks" button was the only way to turn a synced
event into tasks. v0.3.1 makes it automatic, without breaking the settled rule
that the link is one-directional — **EventCrew may know about EventMesh;
EventMesh must never know about EventCrew.**

**EventMesh's side is a single line:** `EventSynchronizer::sync()` fires
`do_action('eventmesh/event_synced', $postId, $isNew)` after an event is fully
written. It names no EventCrew anything. `$isNew` is the create-vs-re-sync
signal EventMesh already computed for its own counters; exposing it as the
hook's second argument is what lets a listener act on genuinely new events
without re-deriving that from post-meta timestamps.

**EventCrew's side listens and stays quiet by default.**
`Support\EventMeshSyncListener` registers on the hook unconditionally —
`add_action` on a hook nobody fires is inert, so no `post_type_exists()` guard
is needed at registration, and it boots outside admin because a WP-Cron sync
never touches wp-admin. That last point is the whole feature: **a new event's
tasks appear with nobody logged in.** It acts only when `$isNew` is true *and*
the opt-in `eventcrew_auto_create_tasks` option is set — off by default, per
the "Settings lists only controls the shipped code reads" rule, so installing
both plugins together never floods an unreviewed role list into tasks.

Re-sync is deliberately ignored even when it changes the event's time: once
created, the tasks belong to the organizer, and a later correction must not
stomp signups or hand-edits. An event synced before the toggle was on is
handled by the existing manual button — no separate backfill path, and the
reason no per-event re-trigger button was added to EventMesh.

**The shared logic was extracted, not duplicated.** `TasksPage::applyTemplate()`
and the listener both go through `Support\TaskTemplateApplier::apply()` — the
skip-already-scheduled-roles rule lives in exactly one place, which is what
keeps "apply twice is safe" true from both entry points.

### The EventMesh half: Holvi timezone fix

Landed in the same pass, in the EventMesh repo. Every Holvi-imported event had
been 2–3 hours late since the connector shipped: a scraped "18:00" with no
timezone was read under PHP's process zone, which WordPress pins to UTC, so a
Helsinki 6pm became 18:00 UTC = 21:00 local in summer. `Support\LocalTime` now
routes every scraped-date construction through the **site's** timezone, which
is what a source with no zone of its own always means. Real Holvi data carries
no offset anywhere, so there is no "does it state a zone" branch to get wrong —
it simply assumes local. The fix was invisible to the test suite until
`wp_timezone()` got stubbed, because unstubbed it fell back to UTC and hid the
very bug it introduces.

## Done: v0.4 — the Telegram group bot

The whole back office finally gets its front: a bot in the existing group where
people claim jobs. It ships as a new `EventCrew\Telegram` namespace and needed
**no schema change** — v0.1 already provisioned `telegram_user_id` /
`telegram_chat_id` / `email_verified_at`, the hashed `auth_tokens` table, and
the atomic `join()`. `DB_VERSION` stays 2.

**The webhook is the only inbound seam.** Telegram posts every update to a REST
route (`eventcrew/v1/telegram/webhook`), authenticated by the secret token it
echoes in a header — matched in constant time, because Telegram cannot send a
WordPress nonce. With no secret stored the route refuses everything; there is no
unprotected state. `WebhookController`, the verification endpoint and the
board-refresh listener all boot on the front/cron path, for the same reason
`EventMeshSyncListener` does: an update, or a cron-driven task creation, never
touches wp-admin. All three no-op until the bot is configured.

**Identity is still a verified email.** `people.email` is `NOT NULL`, so
onboarding has to collect one: `/start` in the bot's private chat asks for it,
the next message is taken as the answer (held in a short transient), a person is
created or linked, and `AuthTokenRepository` issues a single-use, hashed
magic-link token that `wp_mail` sends. Confirming the link (a public GET, works
signed-out) sets `email_verified_at`. Holding a slot is gated on that — a Join
tap from an unverified account is refused with a nudge to the deep-link button,
so the settled "identity is a verified email" rule holds at the one place it
matters. The Telegram id is unique, so a second email can never steal an account
already linked to someone else.

**Join/leave reuse the existing atomic path untouched.** `BoardService` maps a
button tap to `AssignmentRepository::join()` / `leave()` and turns the outcome
into a private callback alert; the capacity race stays the database's to win.
`hasOverlapping()` is checked before a join, so one person across several events
is fine and only a genuine time clash is refused.

**The board auto-posts on task creation.** Both task-creating paths already
funnel through `TaskTemplateApplier::apply()`, which now fires
`eventcrew/board_stale`; hand-edits and deletes fire it too. `BoardRefreshListener`
reposts or edits the one shared board message in place. Because the listener
runs on the cron path, an EventMesh sync that creates tasks refreshes the board
with nobody logged in — the same "with nobody watching" property v0.3.1 has.

**Multi-event board — resolved, not owed.** The old open question (how the board
shows several events open at once) needed no schema or repository work, exactly
as predicted: `eventcrew_assignments` is unique on `(task_id, person_id)` only.
`BoardService::render()` groups tasks by event and shows an event heading only
when more than one is open, reusing `TaskRepository::upcoming()` +
`occupancyFor()`.

**Bot credentials in Settings.** The token and webhook controls appear on the
Settings page now — allowed under "Settings lists only controls the shipped code
reads" precisely because v0.4 is the release whose code reads them. The webhook
status table there is verification item 2, read live from `getWebhookInfo`.

### Host workarounds (added during the v0.4 install)

The live host is InfinityFree free tier, which blocks two things a webhook bot
needs. Both are opt-in Settings toggles that no-op on a normal host:

- **DNS bypass** — the host can't resolve `api.telegram.org`. `Telegram\DohResolver`
  looks the address up over Cloudflare DoH (reached by literal IP, so it needs
  no DNS itself) and `TelegramClient` pins it per-request with `CURLOPT_RESOLVE`.
- **REST workaround** — the host serves a JavaScript anti-bot challenge on
  `/wp-json`, which Telegram can't run. The webhook can instead be installed on
  `admin-ajax.php` (`WebhookController`'s fallback door: raw body, secret in the
  URL), which the host leaves open. Settings shows the exact test request.

---

## Done: v0.5 — roster & attendance

The bot lets people sign up; v0.5 records **who actually turned up**. The status
lifecycle (`AssignmentStatus`, `signed_up → arrived / completed / no_show / …`)
and the write path (`AssignmentRepository::setStatus`) already existed, so this
was a page and a read-model, **no schema change** — `DB_VERSION` stays 2.

**One read-model, two surfaces.** `Support\RosterAssembler::forDate()` composes
each day's tasks with their people (name, status, whether they still occupy the
slot). The wp-admin **Roster** page renders it with a date picker — defaulting to
the most recent day with tasks, because attendance is marked *after* the event
(`TaskRepository::datesWithTasks()`, past-inclusive, unlike `upcomingDates()`) —
a per-person status dropdown, and per-task "mark all arrived / completed"
shortcuts, each recording the organizer as `changed_by`.

**The bot reads, wp-admin writes.** `/roster` (registered in `setMyCommands`)
returns the same roster read-only, gated to a linked organizer
(`Person::isOrganizer`), so the crew's attendance can't be pulled out of the
group by an ordinary member. Marking stays in wp-admin.

---

## Superseded: v0.3's first half — the verification kit

The bot is not being written until the schema has been proven on a real MySQL,
for the same reason the v0.2 rename landed before any install: a schema defect
found now costs a `DB_VERSION` bump, and the same defect found after the bot
is built costs a data migration on rows people care about.

Two files, neither of which ships in the released plugin:

- **`tools/VERIFY.md`** — the procedure. Install, run the script, click every
  admin screen, deactivate and reactivate.
- **`tools/build-zip.php`** — produces `dist/eventcrew-<version>.zip` for
  **Upload Plugin**. Names what ships explicitly rather than deriving it by
  exclusion, so a new top-level directory has to be considered before it can
  reach a web server, and cross-checks the plugin header's `Version` against
  `EVENTCREW_VERSION` — a mismatch only surfaces after release otherwise.
- **`tools/verify-install.php`** — runs under `wp eval-file`, or in a browser
  as an administrator when the host has no WP-CLI. Six phases: migration ran;
  every declared column and index exists; host assumptions hold (InnoDB,
  varchar(191)); every repository statement executes without a MySQL error;
  six simultaneous joins at a capacity-2 task leave exactly two rows; teardown
  returns the row counts to baseline.

Two details worth keeping:

Phase 2 reads its expectations out of `Schema::statements()` by reflection
rather than restating them, so the check cannot quietly drift from the schema
it is checking — the failure mode a hand-maintained expectation list always
eventually has.

Phase 5 is the only place genuine concurrency gets tested, and it needs WP-CLI:
without a shell there is no way to get a second PHP process into the
conditional insert at the same instant, because the bot's HTTP endpoint — the
other route in — does not exist yet. The browser fallback skips it and says so.

**Owed back:** the script's output and the checklist results. A phase 2 or 4
failure is a schema bug to fix before the bot; a checklist failure is an admin
bug and cheaper.

## Verification owed before v1.0

Carried forward from the planning document, because none of it is covered by
unit tests:

1. ~~**Real install**~~ — ✅ done. 103 checks passed; the one failure (MyISAM)
   is fixed in v0.3. Re-run `tools/VERIFY.md` after upgrading, since the
   migration now converts engines and widens two columns.
2. **Webhook reachability** — now runnable (bot shipped in v0.4). Read live off
   Settings → EventCrew after installing the webhook: `getWebhookInfo` shows
   `pending_update_count` 0 and no `last_error_message`. HTTPS with a valid
   certificate is a hard prerequisite; Telegram refuses self-signed and plain
   HTTP. *Owed to actually run on the real host.*
3. **Concurrent capacity, scripted** — now runnable via
   `tools/concurrency-check.php`: it fires six simultaneous joins at a capacity-2
   task over the live webhook (`curl_multi`, no shell needed) and asserts exactly
   two claimed a slot. This is the failure the group surface makes likely and
   that manual testing will not reproduce. *Owed to actually run on the real
   host — the script exists and is packaged.*
4. **Notification cron** — trigger twice, assert one message per recipient per
   kind across both runs; fill every task and confirm the open-task call sends
   nothing.
5. **One real delivery to a Gmail inbox**, to an opted-in account and a
   non-opted-in one — the first arrives and isn't in spam, its unsubscribe link
   works signed-out, the second receives nothing. Ship bulk mail only once SPF
   and DKIM pass on the sending domain, or it poisons deliverability for the
   transactional reminders too.

## Local dev environment

The `php` on PATH loads no `php.ini`, and Composer is not installed globally.
Build a minimal ini enabling `openssl`, `curl`, `mbstring`, `fileinfo` (and
`zip`, which `tools/build-zip.php` needs) with `extension_dir` pointed at the
PHP install's `ext/`, fetch `composer.phar`, and run everything as
`php -c <ini> <command>`. Keep that ini outside the repo — which means
rebuilding it each session, a few seconds' work:

```ini
extension_dir = "<php-install>\ext"
extension=openssl
extension=curl
extension=mbstring
extension=fileinfo
extension=zip
```

PHP 8.3 lives under `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_*`.
Then `php -c <ini> vendor/phpunit/phpunit/phpunit` and
`php -c <ini> vendor/squizlabs/php_codesniffer/bin/phpcs`.

There is no local WordPress, MySQL, Docker or WP-CLI on this machine, which is
why install verification is a script handed over to be run on the real host
rather than something reproducible here.
