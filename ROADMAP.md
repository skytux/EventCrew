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
| Email is transactional, off by disabling the account (revised v0.6) | Superseded the original opt-in rule. An active, verified account receives the open-task email and personal summaries; the off switch is disabling the account (`/stop` in the bot, or the one-click link in every mail). Verified-only still holds, so we never mail an address nobody proved they control. The `email_opt_in_*` columns are now vestigial. |
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
| v0.6 | ✅ Engagement: cancellation classified by notice, replacement flow, account disable/delete, door tickets, and transactional email (signup/cancel confirmations + the manual open-task send). Reputation *scoring* pulled out to v0.7. |
| v0.7 | ✅ Reputation calculator + one threshold + join gate; credits (`floor(completed/2)−redeemed`), redemption, door-list ∪ credits; `/me` |
| v0.8 | ✅ 24h reminders and the 48h open-task call automated, hourly cron + loopback-free fallback (`CronFallbackTrigger`), batching |
| v0.9 | ✅ Public signup page (shortcode + block), email magic-link self-service, web claim/drop sharing the bot's rules |
| v0.10 | ✅ Installable mobile web app (PWA) over the signup page: manifest, service worker, icons |
| v1.0 | ✅ Diagnostics page, translation pass (POT + loader), README refresh, packaging + CI |
| v1.0.1 | ✅ Patch: Diagnostics activity panel + token-from-constant; treat benign Telegram errors (no-op board edit, stale callback) as success; POT dropped |
| v1.0.2 | ✅ Fix: concurrency-check left the update-id high-water mark at 900000000, silently dropping every real update (dead bot). Script now restores it; reinstalling the webhook resets it |
| v1.0.3 | ✅ Settings webhook table shows the last-error age ("X ago") and notes that Telegram never clears it on success, so a stale error reads as stale |

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

## Done: v0.6 — engagement: cancellation integrity, account control, tickets & transactional email

Reconciled against the original planning document after features had been
slipping across iterations; the pieces here are ones it always specified. One
schema change: `people.disabled_at` → **`DB_VERSION` 3**.

**Cancellation is now recorded, not deleted.** Leaving via the bot sets a status
instead of removing the row, classified by notice against a configurable
`eventcrew_notice_hours` (default 48): inside the window it is a `late_cancel`
(0.4 in the reputation weights the calculator will use), earlier a neutral
`cancelled`. `status_changed_at` stamps the *when*. Keeping the row forced a
**re-join reactivation** — `AssignmentRepository::join()` reactivates a freed row
under the same capacity guard (`JOIN_REJOINED`) rather than hitting the unique
key. This surfaced a latent bug: `AssignmentStatus::occupying()` counted
`replaced`, so a replaced slot never reopened — now excluded, which corrects the
counter everywhere it's read.

**The replacement flow — cover-initiated, no admin.** The person *stepping in*
runs `/replace`, names who they're covering (a Telegram text-mention resolves
exactly, otherwise a name search), and the bot lists *that* person's upcoming
tasks. Picking one swaps them in a single step: the original is marked
`replaced` (0.8 — their credit for arranging cover, and it frees the slot) and
the cover is signed up in their place; both are told and the group is told. No
organizer step. And `update_id` idempotency (the original specified it, we'd
never built it) now drops a redelivered update before it can double-process a
cancel.

**Accounts are transactional, with a real off switch.** `people.disabled_at`;
`acceptsOpenTaskEmail()` is verified-and-not-disabled; `/stop` switches off,
`/start` back on; and a signed **manage** page (POST buttons, never a
prefetchable GET) offers *disable* and a full GDPR *delete*. This revised the
settled opt-in decision above.

**Tickets and transactional mail.** `Support\SignedLink` (stateless HMAC, no
storage) powers a public **ticket** page that reads an assignment's live status
(VALID / DISABLED) and the manage link. `Support\Mailer` sends signup
confirmations (with the ticket) and cancellation notices (with standing
guidance), each footered with the manage link. The **open-task email**
(`Support\OpenTaskCall`) mails active, not-already-signed-up accounts the open
tasks plus their own last-3 and total, deduped through the `notifications`
ledger — triggered by a manual "Send now" button; the automated 48h cron stays
v0.8.

**Deferred, and tracked so it isn't lost again:** reputation *scoring*
(calculator + weights + threshold), credits/redemption/door-list-∪-credits, the
cron notification automation + `CronFallbackTrigger`, the public web signup page,
and the Diagnostics page. Every email path is **mailer-dependent**, so its real
verification waits on a host that can actually send (the InfinityFree free tier
is the open question).

## Done: v0.7 — reputation, credits & the door list

The inputs v0.6 captured become the reward model the plugin always promised.
One schema change: `redemptions.redeemed_for` → **`DB_VERSION` 4** (the
`redemptions` table itself was pre-provisioned in v0.6; it just lacked the event
date the door list keys on).

**Reputation is a recency-weighted read of history, never a stored score.**
`Support\Reputation` (pure) weights terminal outcomes — `completed 1.0 /
replaced 0.8 / late_cancel 0.4 / no_show 0.0` — and halves an outcome's pull
every 180 days, so a rocky start fades as someone becomes reliable and a good
record does not excuse a fresh run of no-shows. Fewer than three completed tasks
is **unrated**; above that, one threshold (`eventcrew_reputation_threshold`,
default 0.6) splits good standing from at-risk. `Support\Credits` (pure) is
`floor(completed/2) − redeemed`, clamped at zero. `Support\StandingCalculator`
composes both from storage so the People list, the door list, the join gate and
`/me` can never disagree about where someone stands.

**The join gate.** An at-risk member who taps Join in the bot is asked to talk
to the organizer instead — behind `eventcrew_reputation_gate` (default on), which
the organizer can switch off. New and good-standing members are never touched,
and the gate reads no history at all when it is off.

**The door list — workers ∪ credits.** The Roster page, which is the door list
by the settled decision, now shows for the picked date everyone who gets in free:
those working it, plus those spending a credit, each with a standing badge. The
organizer redeems a credit for anyone who has one (`Support\DoorList` finds the
candidates, the balance is re-checked at write time so a stale page can't
overspend), and a Remove link hands a credit back for a door mistake. Redemptions
are stamped with the event date they buy entry to, not when the button was
pressed.

**Self-service `/me`.** A verified member DMs `/me` and gets their standing,
credit balance and last three tasks — read-only, the one window into what the
organizer sees on the People list. **GDPR:** deleting a person now clears their
redemptions too, in both the self-service manage page and the organizer's People
screen.

**Deferred, still tracked:** the 24h reminder and 48h open-task cron automation
plus `CronFallbackTrigger` and batching (v0.8), the public web signup page
(v0.9), and the Diagnostics page, translation pass, README and packaging CI
(v1.0). This sprint is **mailer-independent** — the standing, credits and door
paths are wp-admin and bot callbacks — so unlike v0.6 it can be verified in full
on the real host without a working mailer.

## Done: v0.8 — scheduled notifications

The plugin stops needing a finger on the button: the reminders and the open-task
call now fire on a schedule. **No schema change** — `assignments.reminded_at` and
the `notifications` ledger already existed; `DB_VERSION` stays 4.

**Two sends, both batched and idempotent.** `Support\ReminderCall` nudges
everyone signed up for a task starting within 24h — a Telegram DM *and* an email
to whoever has each (the DM still goes to a disabled account, since it's a
commitment they made; the email doesn't) — guarded once-per-assignment by
`reminded_at`/`markReminded()`. `Support\OpenTaskCall::sendDue()` sends the
open-task email for any date whose event is within 48h and still has open slots,
deduped through the ledger; the manual "Send now" button stays. Each run stops at
25 sends so a shared-host request can't time out, and the next run resumes on
what the guards left undone.

**One heartbeat, reachable two ways.** `Support\Scheduler` owns a single hourly
WP-Cron event that runs both sends and stamps a last-run time; the schedule
self-heals on boot, so a file-drop update can't leave the site with no heartbeat,
and it clears on deactivation. Because WP-Cron fires with nobody logged in, the
event registers on the front/cron path like the other listeners.

**The loopback-free fallback.** WP-Cron spawns its work with a loopback request
the target host (InfinityFree) blocks. `Support\CronFallbackTrigger` — **off by
default, enabled in Settings** — instead runs any due batch inline on an ordinary
request (the bot webhook counts, so the board being used keeps the heartbeat
going), throttled to hourly and lock-guarded so a request flurry can't double-run.
The primary trigger stays real WP-Cron, which the user's external webcron already
pings hourly. Settings shows the next and last run so cron liveness is visible.

**Deferred, still tracked:** the public web signup page (v0.9), and the
Diagnostics page, translation pass, README and packaging CI (v1.0). Email
delivery is **confirmed working on the real host**, so unlike the earlier
mailer-dependent caveats this sprint is verifiable end to end.

## Done: v0.9 — the public web signup page

A second front door beside the Telegram group: a web page where someone who
isn't in the group can see the open-task board and claim a slot, identified by
email. **No schema change** — `auth_tokens`, `people` and `assignments` already
carry everything; `DB_VERSION` stays 4.

**One rulebook, two channels.** The gate + overlap + capacity checks that lived
inside `BoardService` moved to `Support\SignupService`, which both the bot and
the web now call. The two surfaces keep only their own wording; the decision
"may this person take this slot?" has one answer, so they can never drift. (The
`eventcrew_reputation_gate` / `eventcrew_notice_hours` options moved with it.)

**Magic-link identity, no WP user.** `[eventcrew_signup]` (and a buildless
dynamic block that shares its PHP renderer) shows the board. A visitor enters an
email; `SignupController` finds or creates the person, issues a single-use
`auth_token` and mails a link. Clicking it both **verifies the email** and starts
a session, so the same person can move between the web and the bot. The session
is a stateless, HMAC-signed **`Support\WebSession`** cookie (~30 days) — nothing
stored server-side, matching the `SignedLink` style — with a per-person CSRF
token guarding the claim/drop forms, since a logged-out visitor has no nonce.

**Host-aware, like the bot.** The actions run over `admin-ajax.php`, not the REST
API, because the target host serves a JS challenge on `/wp-json` (the same reason
the webhook has an admin-ajax fallback). The page itself is an ordinary WP page.
Claiming respects verification, capacity, overlap and the reputation gate exactly
as the bot does, and the confirmation/ticket emails already built fire the same
way.

**Deferred, and the last tracked item:** the Diagnostics page, translation pass,
README and packaging CI — **v1.0**.

## Done: v0.10 — the installable mobile web app (PWA)

The v0.9 signup page becomes an app you add to a phone home screen and launch
full-screen. **No schema change**, no native code, no build step; `DB_VERSION`
stays 4.

**`Web\PwaController`** serves three assets as ordinary front-end requests
(`?eventcrew_pwa=…`, so `/wp-json` blocking is irrelevant): the **manifest**
(`Support\WebManifest`, a pure builder — `display: standalone`, `start_url` on
the signup page, 192/512/maskable icons), the **service worker** (a small
network-first worker with a `fetch` handler, served from the site root so its
scope covers the origin), and the **icons**. Icons prefer the WordPress **Site
Icon** when the site has one (`get_site_icon_url`), otherwise `Support\AppIcon`
draws a lettered icon in the theme colour via GD (and degrades gracefully where
GD is absent). On the configured page it injects the `<link rel="manifest">`, the
Apple `apple-mobile-web-app-*` / `apple-touch-icon` tags, and the service-worker
registration, so **both Android (manifest + SW install prompt) and iOS (Add to
Home Screen)** work.

**Settings → Mobile app** picks the signup page (a `wp_dropdown_pages` picker —
the app's `start_url`), the app name (defaults to the site name) and a theme
colour. Also in this release: a **fix** to the v0.9 signup page, which sent every
action to the homepage because it built its return URL with
`add_query_arg([], null)` (an empty URI); it now uses `get_permalink()`.

**Deferred, the last tracked item:** the Diagnostics page, translation pass,
README and packaging CI — **v1.0**.

## Done: v1.0 — diagnostics, i18n, README & CI

The last tracked sprint: the polish that makes the plugin handable to someone who
was not in the room while it was built. **No schema change** — `DB_VERSION` stays
4.

**A read-only Diagnostics page.** `Support\HealthReport` runs a set of
self-contained checks — schema (tables present, version current), the Telegram
bot (token + webhook installed), the notifications heartbeat (last run, and
whether WP-Cron looks stalled), the signup page (chosen, published, and carrying
the shortcode or block), the app icons (Site Icon / GD / neither) and mail — each
returning a `Support\Diagnostic` (status + label + a plain-English detail).
`Admin\DiagnosticsPage` renders them as a status table under EventCrew →
Diagnostics. It makes **no outbound calls** by design, so the page can never hang
on a slow Telegram round-trip; the live webhook status stays on Settings, where it
already was. Every problem it flags is fixed on the Settings page — the
Diagnostics page never writes.

**The translation pass.** A `Domain Path: /languages` header and a
`load_plugin_textdomain` on `init` (late enough for WP 6.7+'s just-in-time
warning) wire up loading; `tools/make-pot.php` — a small token-based extractor,
since this dev box has no WP-CLI — writes `languages/eventcrew.pot` from the ~343
`__()` / `_n()` strings across `src` and `templates`, carrying each
`/* translators: */` hint and every file:line reference.

**README & CI.** The README was rewritten from its v0.2-era state (it still
called v0.3 "next" and described the superseded opt-in email model) to the real
1.0 feature set, install and configuration. `.github/workflows/ci.yml` runs
`composer lint` + `composer test` (and a packaging build) on PHP 8.2 and 8.3 on
every push. `build-zip` now ships `README.md` and `languages/`.

**No longer packaged:** with every host check below green, the `tools/`
verification kit (`verify-install.php`, `concurrency-check.php`, `VERIFY.md`) has
come out of the shipped zip as of v1.0. It stays in the repo for re-running
against a future host; `build-zip.php` documents how to re-add it.

### Post-1.0 polish — reconciling against the original spec

A pass back over the planning document (`imperative-foraging-galaxy.md`) after
v1.0 turned up three items it named that had quietly not landed. Two were closed;
the rest are decisions, tracked below rather than lost.

- **Diagnostics now shows recent activity.** The ring-buffer `Logger` existed and
  was written to everywhere (Telegram, mail, DoH, boot), but the Diagnostics page
  never read it back — exactly the "recent log entries / last update received" the
  spec put there. `HealthReport::recentActivity()` (newest first) and
  `lastUpdateId()` feed a new panel on the page, so the actual last error is
  visible instead of guessed. Still no outbound calls: both read options only.
- **Bot token can come from `wp-config.php`.** `EVENTCREW_TELEGRAM_TOKEN` is now
  read before the option (`TelegramClient::token()`), so the secret can stay out
  of the database and its backups; a Settings hint points at it. The option
  remains the fallback the Settings field writes.
- **Still decisions, not bugs (deliberately not changed):** the open-task email
  no longer carries its own GDPR opt-in (superseded in v0.6 — worth a conscious
  ruling on whether "verified + not-disabled" is consent enough for the *bulk*
  call); bot onboarding uses a magic link rather than the spec's 6-digit code
  (reintroduces the inbox round-trip the code avoided); no 429/`retry_after`
  backoff on group board edits (the join still commits — only the cosmetic edit
  can stall under a tap burst); and Diagnostics still doesn't distinguish plain
  `wp_mail` from authenticated SMTP.

## Verification — all green on the real host (InfinityFree)

Carried forward from the planning document, because none of it is covered by unit
tests. **All five now pass on the live host.**

1. ~~**Real install**~~ — ✅ done. 103 checks passed; the one failure (MyISAM)
   is fixed in v0.3. Re-run `tools/VERIFY.md` after upgrading, since the
   migration now converts engines and widens two columns.
2. ~~**Webhook reachability**~~ — ✅ confirmed working on the real host.
3. ~~**Concurrent capacity, scripted**~~ — ✅ **PASS (2 of 6)**. Getting there took
   two host-specific fixes to `concurrency-check.php`: InfinityFree disables the
   `curl_multi_*` family (while still defining `curl_multi_init`), so the script
   now detects `curl_multi_exec` specifically and falls back to raw non-blocking
   sockets — connect all, then write every request back to back so they still
   race — and it fires at `WebhookController::webhookUrl()`, exercising whichever
   door is installed (the admin-ajax fallback included) rather than the REST route
   that host rejects. The capacity guard held: exactly two of six simultaneous
   joins claimed the last two slots.
4. ~~**Notification cron**~~ — ✅ confirmed functional on the real host.
5. ~~**One real delivery**~~ — ✅ confirmed working (the mailer is verified on the
   host). Continue to ship bulk mail only while SPF and DKIM pass on the sending
   domain, so it doesn't poison deliverability for the transactional reminders.

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
