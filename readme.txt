=== EventCrew ===
Contributors: skytux
Tags: events, attendance, telegram, rsvp, roster
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.8.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Staffing, attendance and rewards for recurring events, run from a Telegram group and a public web page, administered from WordPress.

== Description ==

EventCrew turns a recurring event into a self-running crew: people sign up for
jobs from a Telegram group or a public web page, attendance is marked at the
door, and reliable helpers earn free entry — all administered from wp-admin.

A person's identity is a **verified email**, never a WordPress account, so the
same person moves freely between the bot and the web page, and organizers are the
only ones who need a wp-admin login.

The model is deliberately generic: a **Role** is a kind of job, a **Task** is one
instance of a role on a date needing N people, and an **Assignment** is one
person on one task. Nothing assumes the work is unpaid.

= What it does =

* **Tasks & roles** — define roles with capacities and time offsets, then generate
  a whole evening's tasks for an event in one click. A task set to 0 people stays
  on the books but drops off the boards.
* **A Telegram bot** — a shared board in your group with a Join button per open
  task, private-chat onboarding that verifies an email, and atomic, capacity-safe
  joins even under a double-tap.
* **Public web signup** — an `[eventcrew_signup]` shortcode (and matching block)
  showing the same board, with email magic-link sign-in and claim/drop that obey
  exactly the same rules as the bot. Installable as a mobile app (PWA).
* **Roster, attendance & a door list** — mark who arrived / completed / no-showed,
  per person or a whole crew at once, and see everyone who gets in free tonight.
* **Reputation, credits & a crew-leader role** — a recency-weighted read of each
  person's history (never a stored score), earned free-entry credits, an optional
  gate for at-risk members, and a trusted leader who can run a night without being
  an admin. All tunable in Settings.
* **Notifications on both channels** — every notice can reach a person by Telegram
  DM and email, each type toggleable per channel from a web tab or the
  `/notifications` menu. Signup confirmations and reminders always send.
* **Scheduled sends** — 24h task reminders and a 48h open-task call on an hourly
  WP-Cron heartbeat, with a fallback for hosts where WP-Cron never fires.

= Telegram commands =

Personal commands answer in a private chat; asked in the group, the answer goes to
the DM with a short breadcrumb left behind.

* `/start`, `/stop` — set your account up (verify email) or switch it off
* `/me`, `/myhistory`, `/mytickets` — your standing & credits, past tasks, tickets
* `/ticket` — spend a free-entry credit for a door ticket
* `/replace` — arrange cover for a task
* `/notifications` — choose what reaches you, and on which channel
* `/board` — post or refresh the group board of open tasks
* `/roster` — the attendance roster (organizers, and the night's crew leader)
* `/gift`, `/allow`, `/leaders` — organizer tools: credits, permissions, leadership

== External services ==

EventCrew connects to the following third-party services. None is contacted until
you configure it, and no analytics or personal data is sent anywhere for tracking.

* **Telegram Bot API** (`api.telegram.org`) — used only when you add a bot token in
  Settings. The plugin registers a webhook and sends the messages, board updates
  and notifications your crew receives. Data sent: the message text and chat ids
  needed to deliver them. Telegram's terms: https://telegram.org/tos ; privacy
  policy: https://telegram.org/privacy
* **Cloudflare Turnstile** (`challenges.cloudflare.com`, `challenges.cloudflare.com/turnstile/v0/siteverify`)
  — an optional CAPTCHA on the public sign-in form, active only when you enter
  Turnstile keys in Settings. The visitor's challenge token (and optionally their
  IP) is sent to Cloudflare to verify they are human. Terms:
  https://www.cloudflare.com/website-terms/ ; privacy:
  https://www.cloudflare.com/privacypolicy/
* **Cloudflare DNS-over-HTTPS** (`cloudflare-dns.com`) — used only if you enable the
  "DNS bypass" option, for hosts whose DNS cannot resolve `api.telegram.org`. It
  sends that hostname to Cloudflare's resolver to obtain an IP. Same Cloudflare
  terms and privacy links as above.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, then activate.
   Activation creates the custom tables; the same migration also runs on any later
   request where the stored schema version is behind, so an update dropped in over
   FTP is safe.
2. Under **EventCrew → Settings → Telegram**, paste your BotFather token, save,
   then *Install / refresh webhook* and add the bot to your group. HTTPS with a
   valid certificate is required — Telegram refuses plain HTTP and self-signed
   certs. Re-run the webhook install after upgrades so new commands appear.
3. Create a page containing the `[eventcrew_signup]` shortcode for the public
   board, and (optionally) point the Mobile app setting at it.
4. Define your roles under **EventCrew → Tasks / Settings**, then generate an
   event's tasks.

Requires MySQL/MariaDB with InnoDB; the plugin converts its own tables if the host
defaults to MyISAM.

== Frequently Asked Questions ==

= Do crew members need a WordPress account? =

No. Identity is a verified email address. People sign up from Telegram or the web
page; only organizers use wp-admin.

= Does it work without Telegram? =

Yes. The public `[eventcrew_signup]` page mirrors the board and uses email
magic-link sign-in. Telegram is optional; add a bot token to enable it.

= What happens to attendance history when I uninstall? =

Nothing is deleted by default — a season of history survives an uninstall/reinstall.
Tables are dropped only if you set the `eventcrew_delete_data_on_uninstall` option.

= How is "standing" calculated? =

It is a recency-weighted average of each person's finished outcomes (completed,
replaced, late-cancelled, no-showed), never stored — always recomputed — so it
cannot drift. The outcome weights, threshold and half-life are editable in Settings.

== Changelog ==

= 1.8.0 =
* Add-to-calendar: signup confirmations (email and Telegram) and the web profile now carry an "Add to calendar" link that drops the task, with its own alarm, into any calendar app — the strongest nudge there is against a no-show.
* "You're on next": the signed-in web profile shows your upcoming tasks up front, soonest first, instead of leaving them to be hunted on the board.
* Smoother sign-in: the web form remembers your email, explains the passwordless one-time link, and offers a clear "resend".
* The 24h Telegram reminder now has a one-tap "Can't make it? Cancel" button, turning a would-be no-show into an early cancel someone else can cover.
* Roster: one global "Update attendance" button saves every status at once, instead of a button per person.
* Removed the web "pause account" control (it duplicated Notifications and could strand a non-Telegram user) and the "Open-task email" column from the People list. Fixed the Settings roles table overflowing sideways.

= 1.7.8 =
* Moved the "Send open-task email now" control from Settings to the Tasks page, where it sits under the task list — it acts on the tasks you're looking at, and now returns you there after sending.

= 1.7.7 =
* A role's default capacity can now be set to 0 in Settings, matching per-task capacity: the role stays defined but generates no active slots, so its tasks drop off the boards until you raise it. Negative values are still clamped to 0.

= 1.7.6 =
* New "Group lock" setting (on by default): the board stays in the first Telegram group the bot joins, so adding the bot to another group — or running /board there — can no longer hijack the board into a different chat. Untick it to move the board on purpose.

= 1.7.5 =
* The email account link is now a single-use, 30-minute sign-in link (like the login form's), so a forwarded or leaked old email can't be replayed as a login. Spent and expired sign-in tokens are swept on the hourly cron so the table stays small.

= 1.7.4 =
* The "manage your account" link in emails now opens your own signed-in profile page (where pausing email and deleting your data live) instead of a bare endpoint. Telegram-only installs keep the standalone self-service page.

= 1.7.3 =
* The signed-in web profile now offers account management directly — pause all emails (switch the account off) or delete your data — reusing the same signed self-service page the emails link to.

= 1.7.2 =
* Open-task and freed-slot calls now also reach crew already working that day (they may want a second, non-overlapping slot).

= 1.7.0 =
* Per-type, per-channel notification preferences (web tab + `/notifications` menu); every notification now sends by DM and email. New `/mytickets` and a "My tickets" web list. Ticket link added to the signup DM; door-list credit redemptions notify the recipient; a late cancellation broadcasts a "spot just opened" call.

= 1.6.0 =
* Permissions and the crew-leader meta-role: `/allow` (leader / one-time at-risk pass / admin), an auto-managed leader board slot, Telegram attendance-marking by leaders, an eligibility analysis with a Leadership page and `/leaders`, and a one-time pass through the sign-up gate. Tasks can be set to 0 people to drop them from the boards.

= 1.5.0 =
* Custom credit quantity + note on `/gift` and the People page; a credit-grant audit log.

= 1.4.0 =
* Configurable reputation outcome weights and thresholds; `/gift` to grant credits; `/start`, `/stop`, `/replace` usable from the group.

= 1.3.0 =
* Self-service free tickets (`/ticket` and the web profile), a per-date close-to-free-entry gate, manual credit grants, board wind-down before midnight, and the reliability score shown as a percentage.

= 1.0.0 =
* First stable release: tasks & roles, the Telegram board and onboarding, public web signup (PWA), roster/attendance/door list, reputation & credits, scheduled reminders and the open-task call, and a diagnostics page.

Full history: https://github.com/skytux/EventCrew/blob/main/ROADMAP.md

== Upgrade Notice ==

= 1.8.0 =
Adds calendar holds, an upcoming-tasks summary, a one-tap reminder cancel, and a single-button roster. No database change.

= 1.7.8 =
The "Send open-task email now" button now lives on the Tasks page instead of Settings. No database change.

= 1.7.7 =
Role capacity can now be 0 (a defined role that generates no active slots). No database change.

= 1.7.6 =
The board is now locked to its first group by default, preventing accidental or malicious moves. No database change.

= 1.7.5 =
The email account link is now a single-use, 30-minute sign-in link, and expired tokens are auto-swept. No database change.

= 1.7.4 =
The email account link now lands on your signed-in profile page. No database change.

= 1.7.3 =
Signed-in web users can now pause emails or delete their data from the profile. No database change.

= 1.7.2 =
Adds per-channel notification preferences and closes every notification gap. After upgrading, re-run "Install webhook" in Settings so the new `/notifications` and `/mytickets` commands appear; the database migrates automatically.
