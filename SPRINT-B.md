# Sprint B — engagement (v0.6)

> **Status: delivered.** Shipped in v0.6. See ROADMAP "Done: v0.6". One schema
> change: `people.disabled_at` → `DB_VERSION` 3. 215 tests, phpcs clean.

## What shipped

- **Cancellation integrity.** Bot leave records a status (kept for history), not
  a delete; classified `late_cancel`/`cancelled` by notice vs a configurable
  `eventcrew_notice_hours` (default 48). `join()` reactivates a freed row under
  the capacity guard (`JOIN_REJOINED`). `occupying()` no longer counts
  `replaced`, so replaced/cancelled slots reopen — the counter is correct
  everywhere. `update_id` idempotency drops redelivered updates.
- **Replacement flow.** `/replace` → pick a slot → name the cover → announced in
  the group, slot freed; organizer marks `replaced` on the Roster.
- **Account model.** `disabled_at`; transactional `acceptsOpenTaskEmail`
  (verified & not disabled); `/stop` + `/start`; signed **manage** page with
  disable and full GDPR delete (POST-only actions).
- **Tickets & transactional email.** `SignedLink` (HMAC), a public **ticket**
  page (live VALID/DISABLED), `Mailer` with a manage-link footer, signup +
  cancellation emails, and the **open-task email** (`OpenTaskCall`) with each
  recipient's last-3 + total, deduped via the `notifications` ledger, sent by a
  manual "Send now" button.
- **UX.** Roles table full-width/responsive; Roster date picker defaults to the
  nearest upcoming date with Upcoming/Past optgroups.

## Explicit backlog after this (from the original plan, still tracked)

- Reputation calculator (`completed 1.0 / replaced 0.8 / late_cancel 0.4 /
  no_show 0.0`, recency-weighted, <3 completed = pass, one threshold) — the
  inputs are captured now. → **v0.7**
- Credits `floor(completed/2) − redeemed`, redemption, door-list ∪ credits. → v0.7
- 24h reminder + automated 48h open-task cron + `CronFallbackTrigger` + batching. → v0.8
- Public web signup page (block/shortcode, magic-link self-service). → v0.9
- Diagnostics page (webhook health, cron, logs, schema). → v1.0
- SMTP / SPF / DKIM deliverability — ops.

## Verify

`phpunit --no-coverage` + `phpcs` clean; `build-zip` → `eventcrew-0.6.0.zip`;
migration adds `disabled_at`. Real-install checks are **mailer-dependent** — see
`tools/VERIFY.md`'s v0.6 note.
