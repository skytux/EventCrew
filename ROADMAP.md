# EventCrew — project brief and roadmap

Written so a cold reader (or a fresh session) can pick this up without the
originating conversation. For the *why* behind the architecture, see the
planning document at `~/.claude/plans/imperative-foraging-galaxy.md`.

## What this is

A WordPress plugin for staffing recurring events. An organizer defines what
needs doing on a given date; people claim those jobs from a Telegram group;
the plugin tracks who turned up and what they earned.

Built for a dance event with three job groups (Decorate, Welcome, Clean),
two people on Decorate, free entry earned by working.

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

**v0.1 is committed** (`11fd8d5`): schema and migrations, shift and volunteer
management in wp-admin, task groups in Settings. 54 tests, phpcs clean.

**Not yet verified against a real install.** The suite fakes `$wpdb`, so it
covers decision logic and query shape but never SQL semantics. `dbDelta` has
not run, no admin screen has rendered, and the conditional insert that guards
capacity has not been proven to actually prevent overbooking.

---

## Next: v0.2 — the vocabulary refactor

**Do this before installing anywhere, and before the bot.**

### Why now, specifically

The rename touches table and column names. Right now no database anywhere
holds these tables, so it costs a find-and-replace and a rewrite of
`Schema::statements()` — `DB_VERSION` doesn't even need to move, because there
is no deployed schema to migrate *from*.

The moment v0.1 is activated on the live site, the same change becomes a data
migration: create new tables, copy rows, remap foreign keys, drop the old ones,
and get it right on a production database holding real signup history. That is
a genuinely risky afternoon versus a genuinely boring one. The cost difference
is entirely a function of ordering.

### Why rename at all

The domain is event organizing, not volunteering. "Volunteer" and "shift"
encode one particular use of the tool — unpaid help on a rota — into the type
names. Paid staff, performers, a committee rota, a conference's session hosts
are all the same shape, and the current vocabulary quietly excludes them. The
reward system stays volunteer-flavoured by design; the *core* need not be.

### The mapping

| Now | Becomes |
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
| `task_slug` column | `type_slug` |
| `Assignment`, `AssignmentStatus` | unchanged — already generic |
| `TaskTypes` | unchanged |

Method and string renames follow: `volunteerIdsAssignedOn` → `personIdsAssignedOn`,
`acceptsOpenShiftEmail` → `acceptsOpenTaskEmail`, `hasOpenSlotsOn` unchanged,
and the user-facing "open-shift call" becomes the "open-task call".

### The one naming conflict, and how it resolves

`Shift` → `Task` collides with `TaskTypes`, which currently names the *groups*
(Decorate, Welcome, Clean). Two options were weighed:

- **`Role` for the group** — reads well ("assign people to tasks by role") but
  collides with WordPress's own role/capability vocabulary (`get_role()`,
  `add_role()`), which in a WP plugin is a real and lasting confusion.
- **Keep `TaskType`** — `Task` and `TaskType` is a standard, unambiguous
  pairing, and the column becomes `type_slug`. **Chosen.**

### Scope guard

This is a rename, not a redesign. No behaviour changes, no schema shape
changes beyond identifiers, no new features. The test suite should go green
with the same 54 tests, renamed. If a test needs its *assertions* changed
rather than its identifiers, something has drifted beyond a rename — stop and
reconsider.

### Open question, deliberately not answered yet

With people and tasks generic, the **reward model** is the remaining
volunteer-specific concept: completing tasks earns free entry. Whether that
becomes optional, pluggable, or simply stays as the one opinionated feature is
worth deciding when v0.4 is built — not now. Renaming it speculatively would
be designing for a user who does not exist.

---

## Release ladder

| Release | Contents |
|---|---|
| v0.1 | ✅ Schema, migrations, wp-admin CRUD, task groups |
| **v0.2** | **Vocabulary refactor (above), then verify on a real install** |
| v0.3 | Telegram group bot: board, deep-link onboarding, email verification, atomic join/leave, board edits |
| v0.4 | Roster and attendance marking, in wp-admin and organizer DMs |
| v0.5 | Reputation, credits, redemption, door list |
| v0.6 | Public signup page, magic-link self-service |
| v0.7 | 24h reminders and the 48h open-task call, cron + loopback-free fallback |
| v1.0 | Translation pass, README, packaging script, CI |

## Verification owed before v1.0

Carried forward from the planning document, because none of it is covered by
unit tests:

1. **Real install** — activate, confirm `dbDelta` created every table, add a
   task and a person through the UI without a fatal.
2. **Webhook reachability** (after the bot lands) — `getWebhookInfo` shows
   `pending_update_count` 0 and no `last_error_message`. HTTPS with a valid
   certificate is a hard prerequisite; Telegram refuses self-signed and plain
   HTTP.
3. **Concurrent capacity, scripted** — fire N simultaneous joins at a task
   with capacity 2, assert exactly 2 assignments exist. This is the failure the
   group surface makes likely and that manual testing will not reproduce.
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
Build a minimal ini enabling `openssl`, `curl`, `mbstring`, `fileinfo` with
`extension_dir` pointed at the PHP install's `ext/`, fetch `composer.phar`, and
run everything as `php -c <ini> <command>`. Keep that ini outside the repo.
