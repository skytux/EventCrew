=== EventCrew ===
Contributors: skytux
Tags: events, attendance, telegram, rsvp, roster
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.14.1
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

= 1.14.1 =
* Sign out is now next to your name above the tabs, reachable from any tab instead of only from Settings.
* The tab underline sits under the word rather than spanning the whole tab.
* The slot dots now show the count beside them ("1 of 2"), so it is clear what they are counting.
* Sign up, Cancel and Full buttons share one width, so they line up down the right edge instead of shifting with the length of their label.
* Sign up is back to its familiar green rather than taking the crew's accent colour — "go" is a meaning, not a brand.
* The sign-in form stacks and centres when a captcha is shown: email field, captcha under it, button below. Before, the three sat in an L that shared no edge.
* Spending a free-entry credit is now a proper block: the date picker is styled like the rest of the page instead of the browser's grey default, on its own line, with the button centred under it.
* "You're on next" breaks where it should — the job on one line, the date and calendar link beneath — instead of wrapping mid-phrase.
* Your standing and credits come before your upcoming tasks, and there is a rule above My tickets.
* "Open our group in Telegram" is centred.

= 1.14.0 =
* The signup page is now organised into three tabs — Board, Me and Settings — instead of one long scroll. The board is still what you land on, and still the first thing anyone sees.
* Signed-out visitors get a simpler page: the sign-in form and the board, nothing else. The "open our group in Telegram" link is no longer shown to people who are not signed in.
* The page's styling moved out of the page itself into a proper stylesheet, so it is cached by the browser and versioned with the plugin. Installed-app users now actually receive design changes; before, the app could keep serving the old look from its cache indefinitely.
* Colours follow your theme, with one accent taken from the theme colour under Settings ▸ Web page — the same colour the installed app and the notification emails use, so the three finally match. The hardcoded greens, reds and Telegram blue are gone.
* Board rows redesigned: the role reads first, the date and time sit under it, and how full a task is shows as a row of dots rather than "(1/2)". Buttons are big enough to hit on a phone.
* Accessibility: the tabs are a proper tablist with arrow-key navigation, event headings no longer skip a level, and the capacity dots carry their real numbers for screen readers.
* With JavaScript turned off the page still works — the tabs become links down one long page, exactly as before.

= 1.13.0 =
* Telegram messages you did not ask for now say who is writing and greet you by name, the way the emails already did. A message that simply arrives — an open-task call, a reminder, a spot opening up — may land weeks after someone last touched the bot, in one chat among dozens; it now opens with your crew's name instead of a bare "Reminder:".
* New Signature setting under Settings ▸ Email: how your crew signs off. It closes every notification email and the Telegram messages that go out to everyone. Blank by default, and nothing is added when it is blank.
* The signature is deliberately kept off messages that answer a command, where the person is mid-conversation, and off the standing notices, where a warm sign-off would land badly.
* New `{{signature}}` tag for the email template, so you can move the sign-off around. Reset the template under Settings ▸ Email to take up the new default placement.

= 1.12.0 =
* EventCrew now finds your Telegram group's link by itself, so the "open the group" button in the open-task email and DM works without anyone pasting a link into Settings. A public group is resolved from its @username; a private one from its invite link, or from a new invite link created for the purpose, when the bot is an administrator. Your own link, if you have set one, always wins and is never overwritten.
* The discovery runs when you install or refresh the webhook, and at most once a week after that — a group link changes approximately never, and a bot that is only a member cannot be told one at all.
* Settings ▸ Telegram bot now shows the link it found, and says plainly what to do when it cannot find one.
* The web signup page's "Open in Telegram" button uses the same resolved link, so it appears on installs that never filled the field in.

= 1.11.1 =
* The open-task message now lists the next five open tasks across all upcoming dates, not just the one date that triggered it, so people can see what is coming and sign up early. On both email and Telegram.
* Both the email and the Telegram DM now offer a link to your crew's group, taken from the group link already set under Settings ▸ Telegram bot. Skipped when no link is configured.
* Emails no longer try to guess an accent colour from your theme's style settings. Themes name their palette entries as they please, so the guess was as likely to land on the wrong colour as the right one; set the colour under Settings ▸ Web page instead.
* Button and wordmark text in emails switches between white and near-black on its own, so a light accent colour stays readable.

= 1.11.0 =
* The open-task call ("some tasks still need people") now goes out at two lead times instead of one — a heads-up a week ahead and a last call 48 hours ahead — and both are configurable under Settings ▸ Reputation & alerts ▸ Open-task call. Previously it went once, 48 hours out, with no setting.
* Fixed: a task added to a date people had already been told about was never announced. The ledger recorded "told them about this date", so the new job went out to nobody. It now re-sends when something has genuinely been added since the last send — and only then, so an unfilled date never turns into a daily drip.
* Nobody gets more than one open-task notice a day. Two events falling due at once used to mean two separate emails; they are now gathered into one message listing each date.
* Fewer database queries per run: the open-task list for a date is now looked up once, not once per recipient.

= 1.10.2 =
* Fixed: 1.10.1 replaced the linked logo with one attached inside the message, and on many sites that produced a broken image instead of a logo. Attaching happens through PHPMailer, which a site sending mail through a relay or an API-based SMTP plugin never reaches — leaving the HTML pointing at an attachment that was never made. The logo is linked again, as it was in 1.10.0.
* The white-on-white problem 1.10.1 also fixed remains fixed: the header band uses your theme colour, so a logo drawn for a dark header is visible.
* Note for sites sending through a relay: the plain-text copy that accompanies each email is added on the same PHPMailer hook, so it may not survive either. Nothing a reader needs is in it alone — it is a duplicate of the HTML — but if you require a plain-text part, turn HTML off under Settings ▸ Email.

= 1.10.1 =
* Fixed: a white logo was invisible in emails. The header band now uses your theme colour instead of the card's white, which is what a logo drawn for a dark header needs. New `{{accent}}` tag for the template.
* Fixed: the logo sat against the left edge rather than centred — `align="center"` does not centre a block-level image.
* Changed the logo to an inline attachment. This was a mistake and is reverted in 1.10.2; upgrade straight past this version.
* Existing templates keep the design they were created with; use Settings ▸ Email ▸ "Reset the template" to take up the new header.

= 1.10.0 =
* Notification emails are now designed HTML: your site's logo across the top, the message in a card, and a button for whatever it is asking people to do — "Show your ticket", "Add to calendar", "Sign in", "See open tasks" — instead of a bare URL in the text. A plain-text copy travels with every message, so a client that will not render HTML still gets the whole thing.
* New "Email template" item under EventCrew opens the design in the normal block editor, with revisions and undo. Keep `{{content}}` where the message goes; `{{logo}}`, `{{footer}}`, `{{site_name}}`, `{{site_url}}`, `{{subject}}` and `{{year}}` are available too. Settings ▸ Email has a "Reset the template" button, so an edit is never a dead end.
* The logo is your site's own — the Customizer logo, falling back to the Site Icon, then to your site's name set as a wordmark. Point Settings ▸ Email at a different image (a wide banner works as well as a square logo) to override it.
* Settings ▸ Email also carries a "Send a test email" button, which mails the real template to your own address.
* Buttons and the wordmark use the theme colour from Settings ▸ Web page, so the emails and the installed app match.
* Emails can be switched back to plain text with one checkbox.

= 1.9.2 =
* Leadership is now a tab on the People page — an in-page tab exactly like the Settings tabs — rather than its own menu item. Tab behaviour is consistent across the admin, and the top-level menu is one item shorter (Tasks · Roster · People · Settings · Diagnostics). Editing a person from the Leadership list opens on the People tab as before.

= 1.9.1 =
* Fixed: the Leadership page returned "you do not have permission" after 1.9.0 — hiding it from the menu also stripped the capability WordPress checks for access. It is a normal menu item again, still grouped with People by a tab.
* Fixed: a role set to 0 people generated a task with capacity 1 that reappeared on the Telegram board. It now generates a capacity-0 task that stays off the boards, the same as a hand-edited 0.
* Settings: the Roles tab now comes first and is named simply "Roles"; the Cancellations settings moved to the "Reputation & alerts" tab.

= 1.9.0 =
* Admin overhaul: the Settings page is now organized into tabs — Telegram bot, Web page, Roles & tasks, Reputation & alerts — instead of one long scroll, and it reopens the tab you were last on. Telegram setup leads, so connecting the bot is the first thing you see.
* New "Getting started" checklist on the Tasks page for fresh installs — connect a channel, create a task, put it in front of your crew — that ticks itself off and disappears once you're set up.
* Leadership moved under People as a tab, trimming the top-level menu.
* Diagnostics now shows an at-a-glance health banner (green / amber / red) above the detail table.
* The advanced reputation "outcome weights" are tucked into a collapsible so the Reputation tab reads simpler.

= 1.8.3 =
* The Telegram webhook and command menu now re-install themselves automatically on the first admin page load after a plugin update — no more clicking "Install / refresh webhook" after an upgrade. The button stays for a manual retry.
* The `/notifications` bot menu now lays out as three columns — the type's name, a 📱 Telegram toggle and a ✉ email toggle — matching the web Notifications tab, so the name no longer doubles as the Telegram toggle.

= 1.8.2 =
* Removed the account "disable / pause emails" concept entirely — it duplicated the per-type Notifications controls and could strand a member who had no Telegram. To stop a kind of email, untick it under Notifications (web) or /notifications (bot); the emailed self-service page now only offers "Delete my data". The now-unused `disabled_at` column is dropped on upgrade. A wanted email (a gifted credit, a reminder, a signup confirmation) is no longer silently swallowed by a stale "disabled" flag.

= 1.8.1 =
* New `/web` bot command: DMs you a one-time, 30-minute sign-in link for the web board, so you can hop from Telegram to the web without typing your email.
* Removed the `/stop` command (account disable), matching the removal of the web "pause account" control — turn individual notifications off under /notifications instead. Re-run "Install / refresh webhook" in Settings so the command menu updates.

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

= 1.14.1 =
Visual refinements to the signup page from 1.14.0: sign out on every tab, clearer slot counts, aligned buttons, a tidier sign-in with captcha, and a modern date picker for spending credits. No database change.

= 1.14.0 =
The public signup page is reorganised into Board / Me / Settings tabs and restyled to follow your theme. The board still opens by default. Styling moved to a stylesheet file, so clear any page cache after upgrading. No database change.

= 1.13.0 =
Unprompted Telegram messages now introduce themselves and greet by name, and a new Signature setting signs off both channels. Set it under Settings ▸ Email, then reset the email template to place it. No database change.

= 1.12.0 =
The Telegram group link is now discovered automatically, so the "open the group" buttons work without configuring one. Make the bot an administrator of your group if it is private and you want this to work. No database change.

= 1.11.1 =
The open-task message now shows the next five open tasks and links to your Telegram group. No database change.

= 1.11.0 =
The open-task call gains a week-ahead heads-up alongside the existing 48-hour last call, is capped at one message per person per day, and now notices tasks added to a date after people were told about it. No database change. Check the new lead times under Settings ▸ Reputation & alerts before the next event.

= 1.10.2 =
Fixes the broken email logo introduced in 1.10.1 by linking it again. If you are on 1.10.1, upgrade. Reset the template under Settings ▸ Email to take up the coloured header band.

= 1.10.1 =
Colours the email header band so a white logo is visible. Contains a logo regression fixed in 1.10.2 — go straight there.

= 1.10.0 =
Notification emails become HTML, with your logo and buttons, and the design is editable under EventCrew ▸ Email template. No database change; existing sends are unaffected apart from how they look.

= 1.9.2 =
Leadership moves from its own menu item to a tab on the People page. No database change.

= 1.9.1 =
Fixes Leadership page access and 0-people roles reappearing on the board, and tidies the Settings tabs. No database change.

= 1.9.0 =
Admin UX overhaul: tabbed Settings, a first-run checklist, Leadership under People, and a Diagnostics health banner. No database change.

= 1.8.3 =
The bot webhook and command menu now refresh themselves after an update — you no longer need to re-run "Install / refresh webhook". No database change.

= 1.8.2 =
Removes the account-disable feature; email is now controlled only by the per-type Notifications preferences. The database migrates automatically (the disabled_at column is dropped).

= 1.8.1 =
Adds a /web sign-in link command and removes /stop. Re-run "Install / refresh webhook" in Settings so the bot's command menu updates. No database change.

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
