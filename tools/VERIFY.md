# EventCrew install verification

> **v0.7 note.** `DB_VERSION` is now **4** — the migration adds
> `redemptions.redeemed_for` (the `redemptions` table already existed); re-run
> this after upgrading and confirm Phase 2 sees the new column. Unlike v0.6,
> v0.7 is **mailer-independent** and fully checkable on the real host: complete
> ≥3 tasks for a person → the People list shows a standing badge and
> `floor(n/2)` credits; on the Roster page for that date, the **Door list**
> shows workers, and "Redeem a credit" moves someone onto it and drops their
> balance (Remove undoes it); with the sign-up gate on (Settings → Reputation),
> an at-risk member's bot Join is refused and allowed again with it off; `/me`
> in the bot returns standing + credits + last-3; deleting a person removes their
> redemptions.

> **v0.6 note.** `DB_VERSION` is now **3** — the migration adds `people.disabled_at`;
> re-run this after upgrading and confirm Phase 2 sees the new column. v0.6 also
> adds paths that are **mailer-dependent** and only provable on a host that can
> send: sign up via the bot → a confirmation email with a **ticket** link arrives
> and the ticket page shows VALID; cancel → a cancellation email and the ticket
> flips to DISABLED; the email footer's manage link disables the account (as does
> `/stop`), and delete removes the person; Settings → "Send open-task email now"
> reaches active, not-already-signed-up accounts once, and a second click sends
> nothing (the send-once ledger).

> **v0.4 note.** The Telegram bot has landed, so two long-owed checks are now
> runnable. Item 2 (webhook reachability) is read straight off the Settings →
> EventCrew page once the webhook is installed — the status table there shows
> `Installed`, a pending-update count, and any last error Telegram reports.
> Item 3 (concurrency) now has its own script, `tools/concurrency-check.php`,
> which drives the join over the real webhook — see "4. Concurrency" below.
> No schema change shipped in v0.4, so `DB_VERSION` is still 2 and this file's
> Phases 1–3 pass unchanged from v0.3.

> **v0.3 upgrade note.** The first run found every table created as MyISAM and
> passed everything else. v0.3 fixes that and widens `tasks.starts_at` /
> `tasks.ends_at` from `time` to `datetime`, so `DB_VERSION` is now 2 and the
> migration does real work on upgrade — it converts engines as well as
> columns. Re-run this after installing v0.3. New checks cover the engine, the
> two widened columns, and a task crossing midnight clashing with one the
> next morning.
>
> Phase 5 of `verify-install.php` still needs WP-CLI to run two PHP processes
> into the conditional insert at once. `tools/concurrency-check.php` (below) is
> the other way in — it hits the live webhook with `curl_multi`, which needs no
> shell, only the installed bot.

Everything below is verification items 1, 2 and 3 from `ROADMAP.md`. Items 4 and
5 (the notification cron and a real bulk-mail delivery) depend on code that does
not exist yet and stay owed.

Do this on **staging if you have one**. If you only have the live site, the
script is still safe — it tags every row it writes and deletes them in its
teardown phase, then asserts the counts are back to baseline — but a first
activation is a first activation, so a snapshot of the database first costs
nothing.

## 1. Install and activate

Build the zip if you do not already have it:

```
php tools/build-zip.php
```

That writes `dist/eventcrew-<version>.zip`, containing a single `eventcrew/`
directory and nothing else — no Composer tree, no test suite. The plugin
autoloads its own classes, so there is no `vendor/` to ship.

1. **Plugins → Add New → Upload Plugin**, choose the zip, install.
2. Activate.
3. Note whether activation produced a fatal, a white screen, or a warning.
   Activation is where `Schema::migrate()` runs, so a failure here means
   `dbDelta` never completed.

The zip includes this file and `verify-install.php` under `tools/` so the
verification kit lands on the host with the plugin. They come out of the
package at v1.0, once there is nothing left to verify.

## 2. Run the script

With WP-CLI, from the WordPress root:

```
wp eval-file wp-content/plugins/eventcrew/tools/verify-install.php
```

Without WP-CLI: copy `verify-install.php` to the WordPress root, open it in a
browser while signed in as an administrator, **then delete it**. The browser
route skips the concurrency phase — there is no way to get a second PHP process
running the same code simultaneously without a shell.

Send me the full output. What it covers:

| Phase | Proves |
|---|---|
| 1. Migration | The version option was written and all six tables exist |
| 2. Schema shape | Every column and index `Schema` declares is actually present, read out of `Schema::statements()` by reflection so the expectations cannot drift |
| 3. Host assumptions | Assignments is InnoDB; `email` is varchar(191); roles and statuses load |
| 4. Live SQL | Every repository statement runs without a MySQL error, and the conditional insert enforces capacity |
| 5. Concurrency | Six simultaneous joins at a capacity-2 task leave exactly two assignments |
| 6. Teardown | Row counts are back to baseline and nothing from the run remains |

Phase 4 is the one that matters most. The conditional insert in
`AssignmentRepository::join()` wraps its occupancy subquery in a derived table
to get around MySQL's refusal to read the insert target directly. A faked
`$wpdb` cannot tell you whether that actually parses on your MySQL version — it
either works or every join returns `full`.

Phase 5 is verification item 3. It is the failure the Telegram group surface
makes likely and that clicking around will never reproduce.

## Bot: webhook reachability and concurrency (items 2 and 3)

These need the bot configured: **Settings → EventCrew**, paste the BotFather
token, save, then **Install / refresh webhook**. Telegram requires HTTPS with a
valid certificate and refuses self-signed or plain HTTP, so this only works on a
properly-served host.

**Item 2 — webhook reachability.** After installing, the Webhook table on that
same page reads the status live from Telegram's `getWebhookInfo`:

- [ ] Status shows **Installed**
- [ ] **Pending updates** is 0
- [ ] No **Last error** row appears

Send the bot `/start` in a private chat, give it an email, and confirm the link
that arrives (item 5's transactional cousin — the verification mail, not the
bulk open-task mail). Then run `/board` in the group and confirm the board posts
with a Join button per open task.

**Item 3 — concurrency, scripted.** With the webhook installed:

```
wp eval-file wp-content/plugins/eventcrew/tools/concurrency-check.php
```

Or, without WP-CLI, copy `concurrency-check.php` to the WordPress root, open it
in a browser while signed in as an administrator, **then delete it**. Unlike
`verify-install.php`'s phase 5, this one needs no shell: it fires six
`callback_query` joins at the live webhook at once with `curl_multi`, against a
capacity-2 task it seeds with six verified test people, and asserts exactly two
claimed a slot. It deletes everything it created and prints `PASS` or `FAIL`.

- [ ] `concurrency-check.php` prints **PASS** (2 of 6 claimed the capacity-2 task)

## 3. Click through the admin screens

The script never renders a page, so this part is yours. A missing template or a
class in a mismatched file fails as a fatal or a `wp_die`, and
`CodebaseStructureTest` catches only the cases it knows to look for.

- [ ] **EventCrew → Tasks** loads, empty-state message reads sensibly
- [ ] Add a task: pick a role, a date, capacity, times. It saves and appears in
      the list.
- [ ] Edit that task, change capacity, save. The change sticks.
- [ ] Sort the task list by each sortable column header.
- [ ] **EventCrew → People** loads
- [ ] Add a person with an email and display name. It saves.
- [ ] Search for them by partial email and by partial name.
- [ ] Edit them, then delete them. The success notice appears after the
      redirect.
- [ ] **EventCrew → Settings** loads and shows the three default roles
      (Decorate 🎈 ×2, Welcome 🙋 ×3, Clean 🧹 ×3)
- [ ] Rename a role, save, confirm it persists. Add a fourth role. Remove it
      again.
- [ ] Delete the task you made in step one.
- [ ] With `WP_DEBUG` and `WP_DEBUG_LOG` on, confirm `debug.log` gained no
      EventCrew notices during any of the above.

## 4. Deactivate cleanly

- [ ] Deactivate the plugin. No fatal, and the site still loads.
- [ ] Reactivate. `dbDelta` runs a second time against tables that already
      exist and must be a no-op — re-run the script and confirm phase 2 still
      passes.

## What to send back

The script's output, plus which checklist boxes failed and what they did
instead. A failure in phase 2 or 4 is a schema bug to fix before the bot is
written; a failure in section 3 is an admin bug and is cheaper.
