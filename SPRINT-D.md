# Sprint D — scheduled notifications (v0.8)

> **Status: delivered.** Shipped in v0.8. See ROADMAP "Done: v0.8". No schema
> change (`DB_VERSION` stays 4). 258 tests, phpcs clean.

## What shipped

- **`Support\ReminderCall`.** A 24h task reminder to everyone signed up for an
  imminent task, once each, on **both** channels — a Telegram DM and an email —
  guarded by `assignments.reminded_at`/`markReminded()`. The DM still goes to a
  disabled account (a commitment they made); the email is held back. Uses a new
  `TaskRepository::startingBetween()` (timed tasks only). Batched.
- **`OpenTaskCall::sendDue($leadHours, $limit)`.** The open-task email, automated:
  sends for any upcoming date within 48h that still has open slots, deduped via
  the `notifications` ledger. The manual "Send now" button and
  `sendForNextOpenDate()` stay.
- **`Support\Scheduler`.** One hourly WP-Cron event running both sends in bounded
  batches (25/run) and stamping a last-run time. Self-heals the schedule on boot;
  `unschedule()` clears it on deactivation.
- **`Support\CronFallbackTrigger`.** Loopback-free fallback for hosts where
  WP-Cron never fires — runs due batches inline on an ordinary request (the bot
  webhook included), throttled hourly and lock-guarded. **Off by default, enabled
  in Settings.**
- **Settings → Notifications.** The fallback checkbox, plus a read-only next-run /
  last-run readout so cron liveness is visible.
- **Deactivation hook** clears the cron event.

## Explicit backlog after this (still tracked)

- Public web signup page (block/shortcode, magic-link self-service). → v0.9
- Diagnostics page, translation pass, README, packaging CI. → v1.0
- SMTP / SPF / DKIM deliverability — ops.

## Verify

`phpunit --no-coverage` + `phpcs` clean; `build-zip` → `eventcrew-0.8.0.zip`; no
migration. Email delivery is confirmed on the real host, so this is verifiable
end to end — see `tools/VERIFY.md`'s v0.8 note.
