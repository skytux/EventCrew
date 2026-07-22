# Sprint C — reputation, credits & the door list (v0.7)

> **Status: delivered.** Shipped in v0.7. See ROADMAP "Done: v0.7". One schema
> change: `redemptions.redeemed_for` → `DB_VERSION` 4. 241 tests, phpcs clean.

## What shipped

- **Reputation (pure).** `Support\Reputation` weights terminal outcomes
  (`completed 1.0 / replaced 0.8 / late_cancel 0.4 / no_show 0.0`) and halves an
  outcome's pull every 180 days. `< 3` completed is unrated; above that one
  threshold (`eventcrew_reputation_threshold`, default 0.6) splits good standing
  from at-risk. Input is exactly `AssignmentRepository::historyFor()`.
- **Credits (pure).** `Support\Credits::balance` = `floor(completed/2) − redeemed`,
  clamped at zero. Never stored — always computed, so nothing drifts.
- **`Support\StandingCalculator`.** The one place a `Standing` (level + score +
  credit balance) is assembled from storage, read by every surface so they agree.
- **Join gate.** An at-risk member's bot Join is refused with a nudge to the
  organizer, behind `eventcrew_reputation_gate` (default on); new and
  good-standing members are untouched, and the gate reads no history when off.
- **Door list (workers ∪ credits).** On the Roster page per date: everyone who
  gets in free — those working, plus credit-redeemers — each with a standing
  badge. Redeem a credit for anyone who has one (balance re-checked at write
  time), Remove hands it back. Redemptions carry `redeemed_for` (the event date),
  not just `redeemed_at`.
- **`/me`.** A verified member DMs `/me` for their standing, credits and last-3.
- **People list.** New Standing and Credits columns.
- **GDPR.** Deleting a person clears their redemptions too — both the
  self-service manage page and the People screen.

## Explicit backlog after this (still tracked)

- 24h reminder + automated 48h open-task cron + `CronFallbackTrigger` + batching. → v0.8
- Public web signup page (block/shortcode, magic-link self-service). → v0.9
- Diagnostics page, translation pass, README, packaging CI. → v1.0
- SMTP / SPF / DKIM deliverability — ops.

## Verify

`phpunit --no-coverage` + `phpcs` clean; `build-zip` → `eventcrew-0.7.0.zip`;
migration adds `redeemed_for`. **Mailer-independent** — see `tools/VERIFY.md`'s
v0.7 note for the real-install walk-through.
