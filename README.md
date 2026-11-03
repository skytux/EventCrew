# EventCrew

[![CI](https://github.com/skytux/EventCrew/actions/workflows/ci.yml/badge.svg)](https://github.com/skytux/EventCrew/actions/workflows/ci.yml)
[![License: GPLv2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

A WordPress plugin for **staffing, attendance and rewards at recurring events** —
run from a Telegram group and a public web page, administered from wp-admin.

Built for a dance event with three jobs (Decorate, Welcome, Clean) and free entry
earned by working, but the model is deliberately generic. A **Role** is a kind of
job, a **Task** is one instance of a role on a date needing N people, and an
**Assignment** is one person on one task. Nothing assumes the work is unpaid.

A person's identity is a **verified email**, never a WordPress account — so the
same person moves freely between the bot and the web page, and organizers are the
only ones who ever need a wp-admin login.

---

## What it does

- **Tasks & roles** (wp-admin) — define roles with capacities and optional time
  offsets, then generate a whole evening's tasks for an event in one click. A task
  set to **0 people** stays on the books but drops off the boards.
- **The Telegram bot** — a shared board in your group, grouped under event and
  date headings with a Join button per open task; private-chat onboarding that
  verifies an email; atomic, capacity-safe joins even under a double-tap; and a
  small, purposeful command set (below).
  Personal commands work from the group too — they answer in your DM and leave a
  short "📬 Sent you a DM" breadcrumb.
- **Public web signup** — an `[eventcrew_signup]` shortcode (and matching block)
  showing the same board, with email magic-link sign-in and claim/drop that obey
  exactly the same rules as the bot. Installable as a **mobile app** (PWA).
- **Roster & attendance** — mark who arrived / completed / no-showed, per person
  or a whole crew at once. This is also the **door list**: everyone in free
  tonight, workers plus credit-redeemers.
- **Reputation, credits & role conditions** — a recency-weighted read of each
  person's history (never a stored score), earned free-entry credits, an optional
  gate for at-risk members, and per-role conditions that tell the organizers when
  someone has done enough to be given a role such as Crew leader. All tunable in
  Settings.
- **Notifications on both channels** — every notice can reach a person by Telegram
  DM *and* email, each type toggleable per channel from a web tab or the
  `/notifications` menu. Signup confirmations and reminders always send.
- **Scheduled sends** — 24h task reminders and a 48h open-task call on an hourly
  WP-Cron heartbeat, with an opt-in fallback for hosts where WP-Cron never fires.
- **GDPR, answered** — name the controller (and a data protection officer, where
  one is designated) in **Settings → Privacy** and the plugin writes the notice
  from it: a public page via `[eventcrew_privacy]` or its block, the same text
  offered to WordPress's own privacy policy, and a line under the sign-in field
  linking to it. The notice describes *this* install — Telegram and Cloudflare
  appear only when they are actually in use. A retention period can be left as a
  stated promise or enforced by the heartbeat, which anonymises dormant records
  while keeping the attendance counts. Export and erase both answer WordPress's
  Tools → Personal Data screens.
- **Diagnostics** (wp-admin) — a read-only health page: schema, bot, cron, signup
  page, icons and mail at a glance.
- **Bot log** (wp-admin) — which bot commands and buttons each person used on a
  day (typed, tapped or run by the helper), without any message text; rows are
  deleted after 60 days.

## Holvi ticket buyers

The names of the people who bought a ticket in the Holvi shop can be put on the
door list in two ways:

- **Paste them in wp-admin.** Open EventCrew → Roster, pick the date, and paste
  the names into "Paste names, one per line" under "Holvi ticket buyers". Press
  Import. The names go to the date shown on the page.
- **Email them.** Send the list from your organizer email address to
  `import.edh@haissous.name`, one name per line in the message, or as a `.txt`
  or `.csv` file (the first column is used). The names always go to the next
  event. Mail from any other address is refused. You get an email back that
  says how many names were added.

A name that is already on the list for that date is skipped, so sending an
updated list adds only the new buyers. "Reset import" on the Roster page deletes
all imported ticket buyers for that date; the crew and the guest list are not
affected.

After every import, each imported ticket with an email address and a ticket
code is emailed to its buyer automatically: one thank-you message with the
ticket, Finnish first and English below it. The message carries the ticket's QR
code drawn in the HTML as black and white table cells, not as an image (the
code printed under it), and a link to the ticket's
page on the site. The desk scans that QR code like any other ticket code. A
guest from a mailed guest list whose line holds an email address
(`Anna Virtanen <anna@example.com> +2`) gets a ticket email the same way; the
desk scanner accepts the guest's QR code too. WP-Cron sends up to 25 emails per
run and keeps going while some are left; a failed email is not retried by
itself. The Holvi card on the Roster page (and on the crew page's Admin tab)
shows "N sent · N failed · N not sent", with "Send a test to me" (one ticket of
that date to your own address, no status changes) and "Send the rest now"
(every ticket not sent yet, failed ones too). Door sales are never emailed. The
email address is deleted 60 days after the event, which ends the ticket page
link.

The same ticket email (same design, Finnish first) is also sent to:

- a guest added by hand with an email address: the guest form in wp-admin
  (EventCrew > Roster) and on the crew page's Admin tab has an optional Email
  field, and in the bot a word with an "@" is the email
  (`/door add Anna anna@example.com +1`). The ticket goes with the next cron
  batch, not at once. The email can only be given when the guest is added.
- a Holvi buyer added at the sales desk with an email address. The row gets a
  signed code `EC-B<id>-<signature>`, and the ticket goes with the next batch.
- a crew member who takes a task (always sent, like the sign-up confirmation)
  or spends a free-entry credit (follows the person's "Free-entry ticket
  ready" email choice). This is a separate email, sent at once, in addition to
  the confirmation email with the ticket link. Its QR holds `EC-C<id>-<signature>`
  for a task or `EC-F<id>-<signature>` for a credit. The desk scanner accepts
  both and finds the person's crew or credit row. Old crew ticket links keep
  working.

When a guest who was sent a ticket is removed from the list, or a task sign-up
or credit whose ticket was emailed is cancelled (by the person, an organizer, or
the swap at the sales desk), the person gets a short "Your ticket is cancelled"
email in Finnish and English. Nothing is sent for a night that is already past.

For the email route, something has to read the import mailbox. Either:

- the site reads it itself, every five minutes: Settings > Email > "Read the
  mailbox from this site". It speaks IMAP, or JMAP over HTTPS for hosts that
  block the IMAP ports (InfinityFree does). No PHP imap extension is needed.
  "Check the mailbox now" runs one check at once and shows the result; or
- a small mail poller runs next to the mail server; see
  `tools/mail-import/README.md`.

Use one of the two, not both.

## Telegram commands

Everyone's commands answer in a private chat; asked in the group, the answer goes
to the DM with a breadcrumb left behind.

| Command | Who | What |
| --- | --- | --- |
| `/start` | anyone | Set yourself up (verify an email); also switches a stopped account back on |
| `/stop` | anyone | Switch your account off — no more email |
| `/me` | anyone | Your standing, credits and upcoming tasks |
| `/myhistory` | anyone | Everything you've done |
| `/mytickets` | anyone | Your door tickets, upcoming and past |
| `/ticket` | anyone | Spend a free-entry credit for a ticket |
| `/replace` | anyone | Cover someone else's task |
| `/notifications` | anyone | Choose which notices reach you, and on which channel |
| `/board` | group | Post or refresh the board of open tasks |
| `/roster` | organizers & the night's crew | The attendance roster; the crew on that night's tasks of a role marked **Opens the sales desk** can mark from it |
| `/gift` | organizers | Give someone free-entry credit(s) |
| `/roletasks` | people with a restricted role | The tasks for their roles (never on the group board): sign up or cancel; organizers can also add someone, crew member or not, to any upcoming task |
| `/roles` | organizers | Give or take away a **one-time at-risk pass** / **organizer** / each restricted role (DJ, VJ, Crew leader, ...). `/allow` is the old name and still works |
| `/eligible` | organizers | Who meets the conditions set on each role. `/leaders` is the old name and still works |
| `/newsletter` | organizers, private chat | Set the text for the next events announcement (`/newsletter <text>`), show it (`/newsletter`), or remove it (`/newsletter clear`) |
| `/followup` | organizers, private chat | Set a one-time thank-you or sorry text for the next post-event email (`/followup came <text>`, `/followup missed <text>`), show both texts (`/followup`), or remove one-time texts (`/followup came clear`, `/followup clear`) |
| `/say` | organizers, private chat | `/say <message>` shows the message as the group will see it, with Confirm and Cancel; Confirm posts it in the crew group. Bold, italics, links, quotes and line breaks are kept. Plain words work too ("post in the group: ...") |

### Plain messages (optional language model)

Under **Settings → Telegram bot → Plain messages** an admin can point the bot at
a language model server with an OpenAI-style `/chat/completions` API (URL, model,
optional API key; the key is stored encrypted and never shown again). With it on,
messages that are not commands and do not answer a question the bot asked go to
the model:

- **Private chat:** every such message gets an answer, in the language it was
  written in. The model knows the open tasks, coming events (EventMesh, when
  installed), the official email address (the contact in **Settings → Privacy**)
  and the text of chosen pages (default: the menu pages). If it does
  not know, it says so and points to `/help`.
- **Who is asking:** the model is told the person's roles and whether they are an
  organizer or welcome crew. It leaves out every task, command and detail meant
  only for others, without saying that it exists. When an organizer writes in a
  private chat, the model also gets the organizer guide and the infrastructure
  note from Settings.
- **Memory:** the bot keeps a private chat for 24 hours (the plain messages and
  its own answers, not commands), so follow-ups like "and on Sunday?" work. Each
  message is dropped 24 hours after it was written. In the group it keeps, per
  person, only the messages written to the bot and its answers, for 24 hours, in
  a record separate from the private chat; other group messages are not kept.
- **Forwarding:** when someone in a private chat gives feedback, is unhappy or
  very happy, or asks for a human, every organizer gets the last 24 hours of that
  chat by email (with the person's name and confirmed email) and a short
  Telegram message, and the person is told. At most once per person per hour;
  only people with a confirmed email. Switch it off in Settings > Telegram bot.
- **Name:** the bot calls itself by the name set in @BotFather, read with
  `getMe` when the webhook is installed.
- **Actions:** the model may also suggest one action the
  person could do themselves with a command; the plugin checks it with the same
  permission rules. Signing up for a task, leaving one and changing the guest
  list need a tap on ✅ Confirm (the offer expires after 10 minutes); showing
  something runs straight away.
- **Group:** the bot answers only when the message is meant for it and it knows
  the answer; otherwise it stays silent. It never acts in the group.
- Limits: 20 messages per person per hour in private and 60 per group per hour
  (both settable). Message text is not logged; private chats are kept for 24
  hours as described above. With the switch off or
  no server set, the bot behaves exactly as before.

`\EventCrew\Support\AssistantSettings::saveConnection($url, $model, $apiKey, $enabled)` sets the
connection from a one-off script (`wp eval`).

### Translated texts

Some texts are written once, in any language, and kept in Finnish and English
(`\EventCrew\Support\TranslatedText`): the bot's About and Description, and the
organizer text of the next events announcement. Each stores the text as written,
one text per language, and a per-language "edited by hand" mark.

- With **Translate texts automatically with the language model** on (the
  default, under the language model settings), a save sends the text to the
  model, which fills every language field not edited by hand. A field in the
  language the text was written in gets the text itself.
- A field changed by hand is marked and not overwritten. **Translate again**
  translates every language, the hand-edited ones too, and clears the marks.
  Emptying a field clears its mark.
- With the switch off, nothing is sent to the model. Without a model, or when
  the translation fails, the fields keep what they had, empty ones get the text
  as written, and the admin sees a notice.
- The four old About/Description fields (English, Finnish) were moved into this
  form once: both texts are kept and marked as edited by hand, and the English
  one becomes the written text.

The board itself is grouped by event: two inert heading rows — `📅` and the
event's name, then its date — introduce each group, and its tasks follow as
Join/leave buttons leading with the job (`🎈 Decorate · 17:00–18:30 · 1/3`).
Below them, under a rule, sit one-tap deep links to onboard a newcomer or open a
member's summary.

The headings are buttons only because Telegram has no inert row; tapping one
does nothing. A task row is told apart from a heading by its role emoji, which
no heading carries.

Overlapping slots are allowed. Whether two jobs whose times run into each other
are actually doable is the person's own call, and refusing it cost a filled slot
to prevent something that was usually fine.

The board redraws itself whenever anything on it changes, and **Settings →
Telegram bot → Refresh the board** forces it now — for after an upgrade that
changes the layout, or to watch a change land. It edits in place and notifies
nobody; `/board` in the group posts a fresh copy at the bottom instead.

## Reputation, credits & role conditions

- **Standing** is a recency-weighted average of a person's finished outcomes
  (completed, replaced, late-cancelled, no-showed), rated once they have enough
  finished tasks. It is never stored — always recomputed — so it can't drift. The
  per-outcome weights, the good-standing threshold, the recency half-life and the
  "rated after N tasks" bar are all editable in **Settings → Reputation**.
- An optional **sign-up gate** holds an at-risk member back from claiming new
  tasks; an organizer can wave one person through once with a **one-time pass**.
- **Credits** buy free entry, earned at one per N completed tasks (N configurable).
  A member spends their own (`/ticket` or the web profile) and an organizer can
  hand them out (`/gift`, the People page, or the door list) — every grant tells
  the recipient, and there's an audit log on the People screen.
- **Role conditions**: each role in **Settings → Roles** can have conditions —
  a number of completed tasks in named roles, or at least n different roles done
  x times each, or both. Meeting them does not let anyone sign up; it is a signal.
  The organizers get a Telegram message and an email the first time someone meets
  a role's conditions and the first time someone is given a role (once per person
  per role). The **Role conditions** tab on the People page and `/eligible` list
  who meets them. **Crew leader** is an ordinary restricted role set up this way.
- **Opens the sales desk**: a per-role setting in **Settings → Roles**. The crew
  marked arrived on that night's task of such a role get the sales desk link, can
  see who has a ticket and the guest list, and can mark the crew with `/roster`.
  Welcome has it on by default.
- **Only on events**: a per-role setting in **Settings → Roles**. With text in
  it, new events get a task of that role only when the event title contains the
  text (upper or lower case does not matter), e.g. `cacao` for a role that is
  needed only at cacao nights. Empty means every event. Tasks that already exist
  are not changed, and a task of the role can still be added to any night by
  hand on the wp-admin **Tasks** screen.
- **Organizers can add anyone to any task** of a night — with `/roletasks`, on
  the crew page Admin tab, or at the sales desk — whether or not the person holds
  the role.
- **Restricted roles** (Organize, plus any role with **Only people with this
  role** ticked in **Settings → Roles**, such as DJ, VJ or Ceremony leader) have
  no limit on sign-ups and never count as places that need filling. Their tasks
  are never on the Telegram group board: only people who hold the role see them,
  on the crew page and with `/roletasks` in the bot, and the sales desk shows
  them only to role holders. One person can hold several roles. Organizers give
  roles on the People edit page, in the crew page's Admin tab, or with `/roles`
  (Organize is the organizer permission itself). With **Add to every night**
  (on by default) every upcoming night gets one task of that role, including
  nights that already exist; switching it off deletes nothing.
- Every sign-up to a restricted task asks **Count toward free-entry credit?**
  (bot, crew page, sales desk, and an organizer adding someone). A No is stored
  on the assignment (`counts_credit = 0`) and that task is left out of credits
  and standing.
- An organizer can put someone on a restricted task (not Organize) from the
  crew page's Admin tab or `/roletasks`, even a person who is not a crew member:
  a name and an optional email. The person is created if the email is new (or
  there is none), given the role and signed up; with an email they get the
  normal sign-up email with their ticket. A person with no email gets a
  placeholder `@added.invalid` address, which is never mailed.

## Notifications

Every person-facing notice — confirmations, reminders, standing hits, credit
grants, ticket links, permission changes, cover arrangements, open-task and
freed-slot calls — can go out by **Telegram DM and email**. Each *convenience*
type has an independent DM and email toggle, set from the **Notifications** tab on
the web profile or the `/notifications` keyboard menu (the two share one store).
Signup/cancellation confirmations and task reminders are always sent; disabling
the account is the hard "no email at all" switch.

## Next events announcement

An email of the coming events (from EventMesh) for anyone who joined, crew or
not. It is **off by default**; switch it on under **Settings → Email → Next
events announcement**.

- **Layout.** Each newly announced event is a centred card: its picture, date
  and start time, title, practical information, a **Buy a
  ticket** button with the price (to the ticket shop), a **Listen to the DJ**
  button when the event has the DJ's music page (EventMesh's embed source URL),
  and a link to the event page. A sold-out event shows **Sold out** instead of
  the ticket button. Every other coming event is listed under **Later**, one
  line each with an 80 px picture, with a link to its tickets. The email also
  has a plain-text part.
- **Practical information.** The language model picks the venue, address and
  door times out of each event's description; a value is shown only when it is
  in the description. The cards show no description text.
- **Joining.** The form is the `[eventcrew_newsletter]` shortcode or the **Next
  events announcement** block (for the site footer and other template parts).
  When the announcement is on, a thin sign-up bar also shows at the bottom of
  public pages (not in wp-admin and not on the crew app page); on a phone its
  label is hidden, and it has an "i" link to the full form when the page has
  one. **Settings** can give the bar a background image from the Media Library,
  shown under a dark layer. The email field is named `eventcrew_email` (with
  `autocomplete="email"`), because Firefox filled a saved street address into a
  field named `email`; a page cached with the old name still works. The form
  has a hidden honeypot field, and a Turnstile check when Turnstile is set up;
  the check shows only when Cloudflare needs a click.
  Joining is double opt-in: the person gets a confirmation email with a signed
  link, and is subscribed only after following it. A sign-up that is not
  confirmed within 7 days is deleted. The table stores the email address, the
  status (pending, confirmed, left), the sign-up time and the confirm time; no
  name.
- **Timing.** The announcement goes out by itself a set number of days before
  each event at a set time (**Send the newsletter**: days, default 7, and a time,
  default the hour the send window opens). Events that fall in the same window
  go into one announcement, and each event is in at most one announcement.
  Before sending, every organizer gets a preview email with the announcement
  exactly as subscribers get it (HTML) and a signed **Stop this announcement**
  link; the crew app's Admin tab has the same button. **Send the preview** is
  either a number of hours after the previous event ends (the default, 0 hours)
  or a number of days before the event at a set time; the first uses the second
  when there is no previous event or it has no end time. The sending is planned
  at the preview time, and at the latest one day before the sending; a preview
  time that is not before the sending time sends the preview then. All times are
  in the site time zone; the hourly run acts at its first run after each time.
  A stopped announcement is not sent. Sending runs in WP-Cron batches of 20.
- **Organizer text.** An organizer can add a text to the next announcement, with
  `/newsletter <text>` in a private chat with the bot, by telling the bot in
  plain words in that chat (the bot shows the exact text with a Confirm button
  before saving it), or in the crew app's Admin tab. The text is written once, in any language, and translated
  into Finnish and English through the language model when it is saved (see
  *Translated texts*). With the translation switch off, without a model, or when
  the translation fails, both languages show the text as it was written and the
  organizer is told. The text is removed after the
  announcement is sent.
- **Language.** Every card and the organizer text carry Finnish first, then
  English, each labelled. A card shows EventMesh's Finnish title under the
  English one when there is a translation. Prices are written the Finnish way
  (`22 €`, `27,50 €`).
- **Leaving.** Every announcement has a one-click leave link and the
  `List-Unsubscribe` / `List-Unsubscribe-Post` headers. A ticket buyer who
  confirmed the announcement keeps their email address past the 60-day buyer
  purge.

## Post-event email

A short email the day after each event to everyone who had a ticket and gave an
email address: imported ticket buyers, door and desk sales, and guests on a
guest list. It is **off by default**; switch it on under **Settings → Email →
Post-event email**.

- **Two versions.** Someone who was checked in at the door gets a warm
  thank-you and an invitation to secure their spot at the next event. Someone
  who was not gets a kind "sorry we missed you" that hopes to see them soon.
  Both are in Finnish first, then English.
- **The text.** The main text of each version (between "Hi Alex," and the
  sign-off) is set under **Settings → Email → Thank-you text (came)** and
  **Sorry text (missed)**. It is written once in any language and translated
  into Finnish and English by the language model while **Translate texts
  automatically** is on, or edited by hand per language. `{event}` becomes the
  event's name and `{date}` its date. Until a text is saved there, the built-in
  text is used, in each language. An organizer can replace either text for the
  next email only, with `/followup came <text>` or `/followup missed <text>` in a
  private chat with the bot (or in plain words; the bot asks to Confirm), or in
  the crew app's Admin tab. It is translated the same way as the newsletter's
  text, shown in the preview email, and removed once that night's emails are
  sent, or when that night is stopped or is too late to send. `/followup` shows
  both texts; `/followup clear` removes the one-time texts.
- **Content.** Under the text are a button to the site, the next three coming
  events as cards with their pictures (the same cards as the next events
  announcement), and, while the announcement is on, an invitation to join it.
  Nobody is subscribed by this email; joining goes through the announcement's
  double opt-in.
- **One per address.** Several tickets of one night with the same email address
  get one email. The person counts as came when any of those tickets was
  checked in. Rows whose address was already deleted by the 60-day purge are
  skipped.
- **Timing.** At 12:00 site time the day before the event, every organizer gets
  a preview with both versions (with a sample name) and a signed **Stop** link.
  The emails go out from 12:00 the day after, in WP-Cron batches of 25. Only a
  night whose preview went out is sent, and a night more than two days late is
  not sent. A night with no ticket holder with an email gets nothing, not even
  a preview. Switching it on never mails an event that is already over or going
  on: the first email is for the first event whose day starts after the switch.
  The Roster page (wp-admin and the crew app's Admin tab) has the
  same Stop button and a **Send a test to me** button.
- **Tracking.** Every link carries `utm_source=eventcrew`, `utm_medium=email`
  and `utm_campaign=thanks-YYYY-MM-DD` or `missed-YYYY-MM-DD`, merged into any
  query string the link already has, so the shop's and the site's statistics can
  tell which sales came from these emails. The link goes through the site first
  (`?eventcrew_go=<signed token>`, answered with a 302) and records the time of
  the first click per email; only signed http(s) targets are followed, so it is
  not an open redirect. The Roster page shows
  "Post-event email: 38 sent · 2 failed · 6 clicked". Mail scanners that open
  links can add to the clicks.

## Requirements

- WordPress 6.8+
- PHP 8.2+
- MySQL or MariaDB with InnoDB (the plugin converts its own tables if the host
  defaults to MyISAM)
- HTTPS with a valid certificate — required by Telegram for the bot webhook and by
  browsers for the installable app. Telegram refuses self-signed or plain-HTTP
  endpoints.

## Install

1. Build the distributable zip:

   ```
   php tools/build-zip.php
   ```

   This writes `dist/eventcrew-<version>.zip` containing a single `eventcrew/`
   directory — no Composer tree, no test suite. The plugin autoloads its own
   classes, so there is no `vendor/` to ship.

2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, install and
   activate. Activation creates the custom tables; the same migration also runs on
   any request where the stored schema version is behind, so an update dropped in
   over FTP (which skips the activation hook) is safe.

Then work through [`tools/VERIFY.md`](tools/VERIFY.md) to confirm the install on
the real host.

## Configure

Everything is under the **EventCrew** admin menu. Settings lists only controls the
shipped code actually reads, so there are no dead switches.

- **Settings → Telegram** — paste the BotFather token, save, *Install / refresh
  webhook*, then add the bot to your group. On hosts that block `/wp-json`, tick
  the admin-ajax fallback; where DNS is filtered, tick the DoH bypass. Re-run the
  webhook install after upgrades so new commands appear in the bot menu. The live
  webhook status is shown on the page. The bot's **About** and **Description**
  are each written once, in any language, under *Write in any language*; the
  Finnish and English fields below it are filled by the language model (see
  *Translated texts*). On save, each language text that changed is sent to
  Telegram: English as the default (no language code), Finnish with `fi`.
- **Settings → Reputation** — outcome weights, the good-standing threshold, the
  sign-up gate, tasks-per-credit, "rated after" and the recency half-life.
- **Settings → Notifications** — enable the on-request cron fallback if your host
  never fires WP-Cron. The next/last run times are shown.
- **Settings → Mobile app** — pick the page holding `[eventcrew_signup]`, and
  optionally an app name and theme colour.

The **Diagnostics** page summarises all of the above and flags anything that still
needs attention.

## Relationship to EventMesh

EventCrew works standalone. If the companion **EventMesh** plugin is installed, a
task can be pointed at a synced `eventmesh_event` post and will use that post's
title, and a new event's tasks can be created automatically on sync. Otherwise a
task carries its own typed event label. The link is one-directional — EventCrew
may know about EventMesh, never the reverse — with no hard dependency either way.

## Data and privacy

Records about people are personal data, so two behaviours are deliberate:

- **Uninstalling keeps everything by default.** Tables are dropped only when
  `eventcrew_delete_data_on_uninstall` is set, so removing the plugin does not
  destroy a season of attendance history; a reinstall picks up where it left off.
- **Identity is a verified email, never a WordPress user.** Email is transactional
  and switched off by disabling the account — `/stop` in the bot, or the one-click
  link in every message. A signed self-service page offers account disable and a
  full GDPR delete; deleting a person clears their assignments and redemptions too.

## Development

Composer is dev-only — the shipped plugin uses a hand-rolled autoloader and
carries no `vendor/` directory.

```
composer install
composer test    # PHPUnit + Brain Monkey — no WordPress or database required
composer lint    # PHP_CodeSniffer, PSR-12 plus the WPCS security sniffs
```

The suite fakes `$wpdb` rather than talking to MySQL, so it covers decision logic
and query shape. Anything depending on real SQL semantics — notably the
conditional insert that enforces task capacity under concurrent signups — is
covered by the end-to-end scripts in `tools/` (`verify-install.php`,
`concurrency-check.php`), run on the real host. CI runs `test` and `lint` on every
push ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

[ROADMAP.md](ROADMAP.md) holds the settled architectural decisions and the full
release history.

## Licence

GPLv2. See [LICENSE](LICENSE).
