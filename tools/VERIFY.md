# EventCrew install verification

> **v0.3 upgrade note.** The first run found every table created as MyISAM and
> passed everything else. v0.3 fixes that and widens `tasks.starts_at` /
> `tasks.ends_at` from `time` to `datetime`, so `DB_VERSION` is now 2 and the
> migration does real work on upgrade — it converts engines as well as
> columns. Re-run this after installing v0.3. New checks cover the engine, the
> two widened columns, and a task crossing midnight clashing with one the
> next morning.
>
> Phase 5 skipped last time for want of WP-CLI. If you can get `wp` onto the
> host it is worth running; otherwise it stays owed until the bot lands and
> the join endpoint can be hit concurrently over HTTP.

Everything below is verification item 1 and item 3 from `ROADMAP.md`. Items 2,
4 and 5 depend on code that does not exist yet and stay owed.

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
