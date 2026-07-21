# Sprint A — Roster & attendance (v0.5)

## Why now

v0.4 shipped the bot: people can claim tasks from the Telegram group. The next
thing an organizer needs is to record **who actually turned up** — the
arrived / completed / no-show marks that v0.6's reputation and credits will be
computed from. This sprint captures only that raw attendance, in the place the
organizer already works (wp-admin), with a read-only view over the bot. Nothing
is scored yet.

## What already exists to build on

- **`Support\AssignmentStatus`** — the lifecycle is defined:
  `signed_up → arrived / completed / no_show / late_cancel / cancelled`
  (plus `replaced`). `occupying()` already says which of these still hold a
  slot; `label()` gives the i18n display string.
- **`Repositories\AssignmentRepository`** — `setStatus(id, status, changedBy)`,
  `forTask(taskId)`, `forPerson(personId)` are all present.
- **`Repositories\TaskRepository`** — `forDate(date)`, `upcomingDates()`,
  `occupancyFor()`.
- **`Admin\TasksPage`** already renders a per-task roster (name + status label)
  when editing a task — the read side is half-built and the pattern to follow.
- **`Models\Person::name()`** for display; `PersonRepository::find()`.

So the storage and status model are done. This sprint is a **UI + a couple of
controller actions**, not new schema (`DB_VERSION` stays 2).

## Scope

1. **Roster admin page** (new "Roster" submenu). Pick a date — default to the
   next date that has tasks (`TaskRepository::upcomingDates()`), list that day's
   tasks (`forDate()`), and under each show its assigned people
   (`AssignmentRepository::forTask()`) with their current status label.
2. **Marking**: a per-person status control (quick buttons or a dropdown)
   posting through a new `admin_post_eventcrew_mark_attendance` handler →
   `AssignmentRepository::setStatus()`, with `changed_by` = the organizer's WP
   user id. Add per-task "mark all arrived / all completed" shortcuts. Follow
   the existing `Admin::assertCanSave()` + PRG + transient-notice pattern.
3. **Bot, read-only**: an organizer `/roster` command that returns the next
   date's roster (who is on what) — no marking from chat this sprint. Gate it to
   organizers (`Person::isOrganizer`, or the sender being a linked organizer);
   decide the exact gate during planning.
4. **Tests**: the repository is already covered; add controller-logic tests
   (status validation, `changed_by`, the "mark all" path) and a roster-render
   test. Keep phpcs (PSR-12 + security sniffs) clean.

## Out of scope (later releases)

- Reputation / credit scoring, redemption, the door list → **v0.6**.
- 24h reminders and the 48h open-task call, notification cron → **v0.8**.

## Carried-over risks & still-owed verification

- The live host (InfinityFree free tier) blocks outbound DNS and anonymous
  `/wp-json`; v0.4's two Settings toggles route around both, but **WP-Cron
  reliability there is untested** — this bites when v0.8's reminders land, not
  this sprint.
- Owed on a real host, from `tools/VERIFY.md`: run `tools/concurrency-check.php`
  (item 3), and one real magic-link email delivery (a cousin of item 5). Worth
  clearing opportunistically while doing the v0.5 install pass.

## Verify

`vendor/phpunit/phpunit/phpunit --no-coverage` and `phpcs` both clean (dev
`php.ini` recipe in `ROADMAP.md` → "Local dev environment"), `tools/build-zip.php`
produces `eventcrew-0.5.0.zip`, and a real-install pass of the Roster page and
`/roster` per `tools/VERIFY.md`.
