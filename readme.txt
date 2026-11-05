=== EventCrew ===
Contributors: skytux
Tags: events, attendance, telegram, rsvp, roster
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.140.72
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
* **A Telegram bot** — a shared board in your group, grouped under event and date
  headings with a Join button per open task, private-chat onboarding that verifies
  an email, and atomic, capacity-safe joins even under a double-tap.
* **Public web signup** — an `[eventcrew_signup]` shortcode (and matching block)
  showing the same board, with email magic-link sign-in and claim/drop that obey
  exactly the same rules as the bot. Installable as a mobile app (PWA).
* **Taking over a task** — a person asks to take over someone's task; the holder
  lets them take it or keeps it, in the bot or from a link in the email. With no
  answer by the deadline, the holder keeps it.
* **Roster, attendance & a door list** — mark who arrived / completed / no-showed,
  per person or a whole crew at once, and see everyone who gets in free tonight.
* **The door** — door list, guest list and a sales desk with a QR scanner; ticket
  buyers, guests and crew get a ticket email with a QR code.
* **Sign in with Telegram** — optional: the crew page's Telegram button opens the
  bot, and the bot sends a button that signs the person in.
* **Event cards** — `/events` posts the coming events in the crew group as picture
  cards; the group board can show the next event as a card.
* **Emails about the events** — a next events announcement and a post-event
  thank-you email, both off by default.
* **Finnish and English** — bot messages, emails and the crew page, in each
  person's chosen language. The group board is in English; event cards are in
  both languages.
* **Plain messages** — optional: with a language model server set, the bot
  answers messages written in plain words.
* **Reputation, credits & role conditions** — a recency-weighted read of each
  person's history (never a stored score), earned free-entry credits, an optional
  gate for at-risk members, and per-role conditions that tell the organizers when
  someone has done enough to be given a role such as Crew leader. All tunable in
  Settings.
* **Notifications on both channels** — every notice can reach a person by Telegram
  DM and email, each type toggleable per channel from a web tab or the
  🔔 Notifications button under `/me`. Signup confirmations and reminders always
  send.
* **Scheduled sends** — 24h task reminders and a 48h open-task call on an hourly
  WP-Cron heartbeat, with a fallback for hosts where WP-Cron never fires.
* **GDPR, answered** — say who the controller is (and a data protection officer, if
  you have one) and the plugin writes the privacy notice: a public page via
  `[eventcrew_privacy]`, suggested text for WordPress's own policy, and a line at
  sign-up linking to it. Plus a retention period you can have enforced
  automatically, and export and erase handlers on WordPress's own privacy tools.

= Telegram commands =

Personal commands answer in a private chat; asked in the group, the answer goes to
the DM with a short breadcrumb left behind.

* `/start` — set your account up (verify email)
* `/help` — the commands you can use
* `/me` — your tasks, credits and standing, with buttons to spend a free-entry credit for a ticket, take over a task, get a web sign-in link, choose which notices reach you and on which channel, and choose the language; `/me history` and `/me tickets` list past tasks and tickets
* `/board` — in the group, post or refresh the board of open tasks; in a private chat, also the tasks of your roles (DJ, VJ, ...), and (organizers) add someone to any upcoming task
* `/roster` — the attendance roster (organizers, and the night's crew on roles that open the sales desk)
* `/door` — door list, guest list and sales desk (organizers and the night's welcome crew)
* `/people` — organizer tools: give credits, roles and permissions, who meets each role's conditions
* `/events`, `/say`, `/newsletter`, `/followup` — organizer tools in a private chat: event cards and messages for the group, texts for the next events announcement and the post-event email

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
* **A language model server of your choice** — used only when you switch on
  "Plain messages" under Settings → Telegram bot and enter its address. No
  server is built in; you choose one with an OpenAI-style chat completions API
  (it can run on your own network). Data sent: the text of a bot message that is
  not a command, whether the sender is an organizer, and the open tasks, coming
  events and the text of the chosen site pages. The message text is not stored.
  The terms and privacy policy are those of the server you choose.

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

= 1.140.72 =
Settings > Email has four new fields under the ticket email text: the line for a bought ticket, a guest-list ticket, a crew ticket and a free-entry ticket, one text per site language. Empty means the built-in line. They are also rows on the Translations page.

= 1.140.71 =
The switch Translate texts automatically with the language model is removed from the Settings page; it is now only on the Translations page. Saving the Settings page never changes it.

= 1.140.70 =
The bot language question and the language switch on the crew page now offer every site language, main language first, each written in its own name (Suomi, English, Svenska...). The bot shows three buttons per row; the crew page switch wraps onto a new line when there are many.

= 1.140.69 =
The organizer notices (role met, role given, made organizer), the forged-request alert, the import emails and the bot chat forward can now be edited on the Messages page. The bot-chat subjects and the transcript line are no longer listed as Bot answers on the Translations page; a hand-edited language text there must be entered again under Messages.

= 1.140.68 =
The takeover request, done and outcome messages can now be edited on the Messages page, for email and Telegram. The bot uses the edited done text too.

= 1.140.67 =
The sign-in link, new-account link, email change, email changed, delete-data and access-changed emails, and the three access-changed Telegram messages, can now be edited on the Messages page. The Email address changed text must contain {email}: a text without it is refused on the Messages page and on the Translations page. These texts are no longer listed as Bot answers on the Translations page; a hand-edited language text there must be entered again under Messages.

= 1.140.66 =
The welcome message, the door new-member email, the sales desk link and the organizer-on-the-night notice can now be edited on the Messages page. The privacy line in the door new-member email is added by code in the member language, so an edited text cannot lose it.

= 1.140.65 =
The sign-up confirmation, cancel confirmation, open-task call and email-confirm texts can now be edited on the Messages page, in every site language.

= 1.140.64 =
The AI newsletter and post-event writers now write one text per site language in one request, not only Finnish and English; texts saved before still work. The two translation prompts (to Finnish, to English) are replaced by one translate prompt: if you had changed them, copy your change to the new prompt on the AI prompts page. The privacy policy now says the text is translated into the other site languages.

= 1.140.63 =
Two help texts said Finnish and English where the plugin now uses every site language: the ticket email in the organizer guide, and the language model setting.

= 1.140.62 =
The newsletter is written and sent in every site language: the subject, the footer and the notes each have a text per language, and each subscriber gets the languages HybridPolicy picks (side by side, at most 3, or their own language when the Hybrid tick is off).

= 1.140.61 =
Messages that go to everyone at once (ticket email, cancellations, post-event email, newsletter confirmation) are written in the site languages side by side, at most 3; a message to one person uses that person's language. Settings > Email has a Hybrid tick per message: untick it to send that message in one language only. Fixes: a person with no language set gets English; the cancellation email names the task that was removed.

= 1.140.60 =
A new Messages page holds every message the plugin sends (email and Telegram) in one list, each editable in every site language, with the placeholders it may use. The AI can write the post-event and other texts per event, in every site language; the AI prompts have their own page. The post-event email text moved from Settings to the Messages page; texts you saved there are moved over once, by themselves.

= 1.140.59 =
Opening an email link a second time no longer says "invalid". A confirmation link opened again (or first opened by a mail security scanner) now says "Your email is already confirmed." A sign-in link now shows a Sign in button and signs in only when you press it, so a scanner opening the link first no longer uses it up. Used sign-in links are kept until they expire, so this works until the link expires.

= 1.140.58 =
The "A spot just opened" message after a late cancellation is no longer sent at once, at any hour. It now goes out from the hourly job inside the send window, and is dropped if the place is filled again, the task has started or was deleted. Blocked people are not told.

= 1.140.57 =
Saving the roster no longer undoes marks made after you opened the page. Before, Update attendance wrote every row shown, so a door check-in, a cancellation or a takeover made in the meantime went back to the old status. Now only the rows you changed are saved; a row someone else changed since you opened the page is left alone, and the notice says how many and asks you to reload. Applies to the wp-admin Roster page and the crew app.

= 1.140.56 =
Site languages and one way to manage them (Q284, Q287, Q292, Q295): Settings lists the site languages (any number for single-language texts, at most 3 shown side by side in hybrid messages). A new Translations page shows every editable text, the bot answers and buttons included, in every site language, with machine drafts you can correct. Every translated field (app name, signature, role names and descriptions, ticket note, ticket email text) works the same way, with a language menu for the text you write. The bot and crew app speak each person their own language when a translation exists, English otherwise.

= 1.140.55 =
The event name at the top of the ticket email can be read again: it was pale cream text on the cream card, now it is the gold heading the other emails use.

= 1.140.54 =
The plugin in Japanese (ja), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Japanese speaker should proofread it. Shown to anyone whose language is Japanese once that language is switched on.

= 1.140.53 =
The plugin in Italian (it_IT), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Italian speaker should proofread it. Shown to anyone whose language is Italian once that language is switched on.

= 1.140.52 =
The plugin in German (de_DE), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a German speaker should proofread it. Shown to anyone whose language is German once that language is switched on.

= 1.140.51 =
The plugin in Polish (pl_PL), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Polish speaker should proofread it. Shown to anyone whose language is Polish once that language is switched on.

= 1.140.50 =
The plugin in Simplified Chinese (zh_CN), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Simplified Chinese speaker should proofread it. Shown to anyone whose language is Simplified Chinese once that language is switched on.

= 1.140.49 =
The plugin in Dutch (nl_NL), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Dutch speaker should proofread it. Shown to anyone whose language is Dutch once that language is switched on.

= 1.140.48 =
The plugin in Russian (ru_RU), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Russian speaker should proofread it. Shown to anyone whose language is Russian once that language is switched on.

= 1.140.47 =
The plugin in Brazilian Portuguese (pt_BR), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Brazilian Portuguese speaker should proofread it. Shown to anyone whose language is Brazilian Portuguese once that language is switched on.

= 1.140.46 =
The plugin in French (fr_FR), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a French speaker should proofread it. Shown to anyone whose language is French once that language is switched on.

= 1.140.45 =
The plugin in Spanish (es_ES), Owner's Q294: every text of the plugin (bot, web pages, emails, wp-admin), machine-drafted; a Spanish speaker should proofread it. Shown to anyone whose language is Spanish once that language is switched on.

= 1.140.44 =
One ticket email for everyone (Q286): the same v4 layout for crew, guests and buyers, with the reason for the ticket in one line, the holder name and a small QR code in the corner under the sign-off. Its text is editable and translated in Settings > Email ("Ticket email"); empty means the built-in text.

= 1.140.43 =
Every email gets the v4 look (Q283): photo or purple backdrop, logo and tagline, cream card, gold headings, white buttons with a gold glow, an italic greeting line, **bold** and *italic*, and a centred "With love," closing; new Settings fields for a background picture and a Finnish sign-off. The cancelled and moved ticket emails use the same look.

= 1.140.42 =
Deleting a task sends each person on it one email: their ticket again with the tasks left, or "Your ticket is cancelled", both naming the deleted task; the "Task cancelled" email only when no ticket was emailed (Q263).

= 1.140.41 =
Ticket emails: the hosting cron takes the same lock as the page-load rescue, and tries again a minute later when the lock is held, so a late batch is never sent twice (Q264).

= 1.140.40 =
Removed the unused AuthTokenRepository::consume(); sign-in links use only the one-time consumeOnce() (Q278).

= 1.140.39 =
Removed the old VPS mail poller (tools/mail-import); the site reads the import mailbox itself (Q275).

= 1.140.38 =
Tests: the whole crew app page is rendered for an organizer, Admin tab included, so a PHP error there fails the tests (Q279).

= 1.140.37 =
Crew app: the text after Telegram opens now says to press the code shown on the page. Settings: the "Test the endpoint by hand" example sends the same empty update as the test button (Q274).

= 1.140.36 =
Telegram /roster: organizers also get the 👋 arrived button, on its own row above ✓ ⏰ ✗, for people still signed up (Q276).

= 1.140.35 =
Telegram /help and the assistant's /roster text now say who can mark what: the sales desk marks arrived, organizers done, late and no-show (Q273).

= 1.140.34 =
Settings: a "Test the webhook" button sends an empty test update from the site with the real secret and shows the HTTP status; the bot sends nothing and the secret is never shown (Q270).

= 1.140.33 =
Telegram sign-in: the bot shows 3 code buttons; only the code shown on the crew app signs in. A wrong press cancels that sign-in, and later presses do nothing (Q268).

= 1.140.32 =
The door desk link, the email verification link and the email change link now work only once, even when opened twice at the same moment (Q269).

= 1.140.31 =
Bot /roster: the sales desk (when not an organizer) can only mark someone still signed up as arrived (👋, no credit). Done, late and no-show stay organizer-only, checked on the server (Q267).

= 1.140.30 =
Tests: the sign-up page is rendered for a signed-in person; a long line in SalesDeskGate is wrapped (Q272).

= 1.140.29 =
Mail import: only the topmost Authentication-Results header is trusted, and only when it names our own mail server. A message without it, or whose top header names another server, is refused; an empty "Trusted mail server" setting refuses every message and says so in the status (Q266).

= 1.140.28 =
The sign-in and profile forms no longer tell whether an email address is registered or blocked (Q255, S-W3).

= 1.140.27 =
A door desk session ends when the person is blocked, erased, or no longer on that night's sales-desk task (Q251, S-W5).

= 1.140.26 =
Telegram sign-in: the page and the bot show the same 4-digit code, and the bot asks to go on only when they match (Q251, S-W6).

= 1.140.25 =
Deleting the account needs a one-time link sent by email, valid 30 minutes, and a button press (Q251, S-W4).

= 1.140.24 =
An emailed sign-in link opened twice at the same moment signs in only one browser (Q251, S-W7).

= 1.140.23 =
Email sign-in form: at most 30 link requests per IP address and 5 per email address per hour (Q248).

= 1.140.22 =
Door desk: "Login as" is removed; the Other device popup shows a QR code and link only for the person signed in on this device (Q247).

= 1.140.21 =
The bot refuses a blocked person everywhere: buttons and private messages get "Your account is blocked. Talk to an organizer.", group messages are ignored (Q246).

= 1.140.20 =
Signed-in pages are never cached; newsletter confirm and unsubscribe links show a button first (one-click unsubscribe still works); the buyer import needs a fresh time stamp; admin notices are kept per administrator (Q258).

= 1.140.19 =
The AI address and the JMAP mail server address must use https (http only to localhost) (Q256).

= 1.140.18 =
The bot removes links to other sites from its answers in the group; links to this site and to Telegram stay (Q254).

= 1.140.17 =
Settings: the bot token, Turnstile secret, webhook secret and import secret are shown only by their last 4 characters; an empty field keeps the saved value and a Remove box clears it; a new import secret is shown in full once. The webhook secret travels in the URL only when that box is ticked (Q252).

= 1.140.16 =
Finnish translation file: removed a second copy of the "EventCrew tasks" entry (no visible change).

= 1.140.15 =
Crew tickets: one ticket per person per night. The ticket email lists the night's tasks; when a task is added or taken away, the email is sent again with the same code and the new list; when the last task is taken away, the ticket is cancelled (Q228).

= 1.140.14 =
A ticket send whose cron job is more than 5 minutes late is run by the next page load (Q232).

= 1.140.13 =
When an organizer changes a guest's email, the old address gets a short TICKET MOVED email (Finnish and English) if the ticket had been emailed there (Q242).

= 1.140.12 =
Door desk: the Holvi step now says the buyer gets the ticket by email in about 10 minutes when an email is given (Q241).

= 1.140.11 =
ROADMAP.md brought up to date with the releases 1.129.0 to 1.140.10 (Q239).

= 1.140.10 =
A task now needs a start time, in wp-admin and in the crew app (Q229).

= 1.140.9 =
A new role starts at the event start (offset 0), and a new task form is filled with the event and its start time (Q209).

= 1.140.8 =
Event edit screen: a "Move the tasks too" checkbox. When ticked and the event start changes, every task of that event moves by the same amount (Q207).

= 1.140.7 =
Newsletter page: a totals line for the time range (joins, leaves, sent newsletters), the Sent newsletters list shows only the time range, and one chart marker per bar, like "1–2" (Q233, Q234, Q235).

= 1.140.6 =
Bot log rows are now deleted after 90 days instead of 60, once a day (Q212).

= 1.140.5 =
Finnish text for "Stop this announcement": "Lopeta tämä ilmoitus" (Q238).

= 1.140.4 =
The newsletter send now uses the same send-time code as the preview email; nothing visible changes (Q236).

= 1.140.3 =
The button in the "you already get the announcement" email now reads "Viesti / Message" (Q213).

= 1.140.2 =
The picture on each newsletter event card no longer shows an underline under it (Q208).

= 1.140.1 =
Telegram: after someone presses Cancel on the ticket send message, the Cancel button is removed from that message.

= 1.140.0 =
Door desk: while the ticket emails wait their 10 minutes, the desk shows when they go out and a Cancel sending button.

= 1.139.0 =
Door desk: the Guest step has an optional email field. With an address, the guest gets the ticket email.

= 1.138.0 =
Organizers can change a guest's email address in the roster. The guest gets a new ticket code and link; the old ones stop working. Database schema 32.

= 1.137.1 =
Tickets: a spent free-entry credit now gets only the one ticket email with the QR code, the same as bought and guest tickets, instead of a second plain email as well.

= 1.137.0 =
Tickets: the cancellation email looks like the ticket email (Finnish, English, then the ticket card) with a red LIPPU PERUTTU / TICKET CANCELLED band and no QR code.

= 1.136.6 =
Docs: README.md and readme.txt list the current bot commands and features; the duplicated release list in readme.txt is removed.

= 1.136.5 =
Settings: when Sign in with Telegram is on but the bot username is empty, the settings page says the button stays hidden until the username is filled in by installing the webhook.

= 1.136.4 =
Bot: in its answers, a sold-out event says "tickets sold out" and gives no price.

= 1.136.3 =
Takeovers: the message in the Telegram group is now in Finnish, then English on the next line.

= 1.136.2 =
Notifications: removed the unused "Cover arranged" switch, and the word "cover" from the privacy text.

= 1.136.1 =
Translations: removed 138 unused strings from the Finnish catalog. A new test checks that every Finnish entry is still used.

= 1.136.0 =
When an organizer deletes a task, everyone signed up for it gets one email in Finnish and English saying the task is cancelled and its ticket no longer works. Nothing is sent when the task has already started or is over.

= 1.135.0 =
Newsletter: database schema 31 adds the joins from before the history began, at each subscriber confirmation time, so the graph shows them. The page says from which day joins are known.

= 1.134.1 =
Newsletter: when a confirmed subscriber is deleted for privacy, the graph counts it as a leave.

= 1.134.0 =
Newsletter page: the graph has a time range. Presets 1 month (default), 6 months, 1 year and 3 years, or your own From and To dates. Bars are per day up to 62 days, per week up to a year, and per month beyond that.

= 1.133.0 =
Newsletter: the preview email is now exactly the email the subscribers will get, built as on the scheduled send day. Only the subject prefix "[Preview]" and a red "Do Not Send This" button at the bottom differ; under the button the page says the send date, time and how many people it goes to.

= 1.132.0 =
New wp-admin page EventCrew > Newsletter: how many people are subscribed, waiting for confirmation and unsubscribed; a graph of joins and leaves per day or week with each sent newsletter marked; and a list of sent newsletters with a copy of each email to open in the browser. The history starts on the day this version is installed. Database version 30 (two new tables, no personal data).

= 1.131.1 =
The organizers' newsletter preview email says "1 subscriber" in the singular and "2 subscribers" in the plural.

= 1.131.0 =
Ticket emails: every real send (import, guest with email, "Send the rest now") waits 10 minutes and can be cancelled from wp-admin Roster, the crew page Admin tab or the /door add reply. "Send a test to me" is still immediate.

= 1.130.1 =
ROADMAP.md brought up to date with the plugin as it is now (sign-in through the bot, built items, VPS dev setup).

= 1.130.0 =
Bot: the assistant knows each coming event's ticket price, the same price the event card shows.

= 1.129.0 =
Ticket email: the ticket is drawn like the ticket page (white card on grey, dark header with the event name and date, large name, larger QR, code, note, link).

= 1.128.2 =
Board: a separator line after each date on the card board (text and buttons), as Owner asked.

= 1.128.1 =
Ticket buyer rows whose name was blanked 60 days after the event show "No name" on the door desk, the crew page Admin tab and the wp-admin Roster. README describes the 60-day blanking. Tests for the purge.

= 1.128.0 =
Telegram board with Event cards on: two messages instead of one per day. First one photo for the next event, its caption a two-sentence text about the DJ or host written ahead by the language model, with its Buy a ticket, Crew app and other links. Below it the text board: the next 3 events, who is signed up for which task, the task buttons, and no date buttons.

= 1.127.0 =
Emails: the banner at the top is bigger (up to 536x140). Two-language texts start with FI / EN tags. The thank-you email names the event by its clean name, shows the DJ text the announcement wrote when it has none of its own, and opens its coming events with a dark centred heading with the English on its own line.

= 1.126.2 =
The mailbox password for reading the import mailbox is now stored encrypted (sodium secretbox, key from the site salts). A password saved before is encrypted on its next read. If it can no longer be decrypted, an admin notice asks for it again.

= 1.126.1 =
With the Event cards setting on, /board in a private chat shows the card format too: one message per day, and a tap redraws only that day.

= 1.126.0 =
Telegram group board in card format, behind a new setting (Settings, Telegram bot, Event cards) that is off by default: one message per day for the next 6 days with tasks; a day with an EventMesh event that has a picture is a photo card with that day task buttons; other days stay text.

= 1.125.4 =
Tests: seven duplicate tests removed (1897 to 1890); no change to the plugin itself.

= 1.125.3 =
Subscribe in the sticky bar sends on the first press again (it did nothing when the page already had its spam check token).

= 1.125.2 =
The Confirm button in the confirmation email stays on one line and is centred in every mail app.

= 1.125.1 =
Sticky bar: when the full form has no Cloudflare token yet, the bar starts its own check in the bar instead of scrolling down to the full form (1.125.0 still scrolled in that case).

= 1.125.0 =
Subscribe in the sticky bar no longer scrolls the page down to the full form: it uses that form's Cloudflare check in the background and shows the answer and the name step in the bar. Only when Cloudflare wants a click does it scroll to the full form as before.

= 1.124.0 =
An address that is already subscribed and is entered in the form again gets one email, at most once an hour, saying it is already subscribed and giving the site email to write to if the announcements do not arrive. The page answer stays the same.

= 1.123.0 =
The confirmation email of the next events announcement is a dark card in the look of the site: the sign-up bar picture on top, a gold sparkle, the heading "Enää yksi askel" with "One step left" under it, the promise "One email before each event. Nothing else.", the Finnish and English text, and a gold "Vahvista / Confirm" button. The footer says why the email came.

= 1.122.1 =
Fix: the sign-up script in the next events announcement box now runs. WordPress changed its "&" characters into "&#038;", which stopped the script, so the form still loaded the page again.

= 1.122.0 =
Fix: the next events announcement form and the bar now really send the sign-up without loading the page again. In 1.121.0 the script sent it to a wrong address and fell back to loading the page.

= 1.121.0 =
The next events announcement form and the bar send the sign-up without loading the page again: the answer, the name question and a new bot check appear in place, in the language of the page. After a sign-up in the full form, the bar hides. Without JavaScript the form works as before.

= 1.120.1 =
After a sign-up to the next events announcement went through, the full form shows only the thank-you: the email field, the Subscribe button and the footnote are gone. A wrong address or a failed bot check still shows the form to try again.

= 1.120.0 =
The next events announcement comes in each subscriber's chosen language: Finnish only, English only, or both when no language is chosen. The language model writes the announcement texts in Finnish first and translates them into English; an English email with translated text ends with a small "Automatically translated" note. Organizers get one preview per language group.

= 1.119.0 =
The DJ line under the event name in the emails and on the Telegram event cards shows only the names, without "DJ" or "DJs" in front.

= 1.118.0 =
The next events announcement greets each subscriber by first name ("Hei Anna! · Hi Anna!", or "Hei! · Hi!" without a name). The thank-you email's Join button subscribes the ticket holder with one press and stores their first name. The website form asks for an optional first name after the address is saved. Each announcement ends with a link to a page where the subscriber can change their name and language or leave. The subscriber's browser language (Finnish or English) is stored; the announcement is still sent in both languages.

= 1.117.0 =
The thank-you and sorry emails after an event no longer have the "See the coming events" button.

= 1.116.0 =
The thank-you and sorry emails no longer force light colours (they have no QR code); mail apps may show them in dark mode like the announcement. Ticket emails stay light-only.

= 1.115.0 =
Announcement and thank-you email event cards: the venue, the address and the door times are aligned left, with an empty line after the address; the door times are one Finnish block and one English block, each with its FI / EN label; the listen button shows the play icon on its own line above the two languages. A card with a picture puts the date, the name and the DJ line in the picture's alt text, styled as a heading, so they show when images are not loaded; the intro texts are aligned left.

= 1.114.0 =
Crew app: on a desktop every tab (Board, Me, Settings, Help, Admin) is as wide as the header card and centred under it, not only the Board.

= 1.113.0 =
Event names without the date and in normal case, from EventMesh's clean names; the DJ names on their own line under the name on the crew app Board, My tasks, the announcement email cards and the admin task pages. Where a line cannot break (a button or a drop-down option) the DJ names follow the name after a dot, or are left out when the line would be too long.

= 1.112.0 =
* Crew app: on a desktop the Board and the tab bar are as wide as the header card and centred under it; the Board shows one column so task names stay whole.

= 1.111.0 =
Next events and post-event emails: a Finnish text and its English translation share one line with a dot only when together they are at most 32 characters; longer pairs (such as the door times) put the English on a new line under the Finnish.

= 1.110.0 =
Post-event email: only the first coming event is a card, with a short warm text the language model writes for this email in the style of the event's DJ (English and Finnish) and the event's practical information; every other coming event is a Later line with a small picture. Written once per night before the preview; if writing fails the card has no text.

= 1.109.0 =
Next events announcement: the event cards no longer show a description or a written event text; the language model picks the venue, the address and the door times out of the description (each checked against it, door codes left out) and the card shows them as short centred lines with the date, title and buttons. The Later list shows a small picture on the left of each event. Organizers get no new preview when only this information is written.

= 1.108.0 =
Post-event email: the thank-you text (people who came) and the sorry-we-missed-you text can be edited in Settings > Email, written once and translated, and an organizer can set a one-time text for the next post-event email in the crew app's Admin tab or with the bot's /followup.

= 1.107.0 =
Newsletter: the opening written by the language model now sees each event's own written text and is told that a DJ name is a person, never a place, so it no longer names a DJ as the venue.

= 1.106.0 =
Next events announcement: Qwen (the language model) writes 2-3 sentences for each event and an opening when the organizers wrote none, in English and Finnish, avoiding the wording of the last 4 announcements. The post-event email shows the same written texts for the same events. Without a written text, the event description is shown as before. On by default (setting: write event texts).

= 1.105.0 =
Bot log: every bot command and button use is recorded (who, when, which command, typed / button / helper, outcome), without any message text, and kept 60 days. wp-admin shows it under EventCrew > Bot log. When the helper runs a command, its private-chat memory gets a short line saying which command it ran. Database version 28.

= 1.104.0 =
Taking over someone's task is now a request the holder must accept. The holder gets a deadline to answer: 24 hours when the request comes less than 48 hours before the task, 48 hours when less than 7 days before, 72 hours otherwise, never later than 1 hour before the task starts. Both people are told the deadline and the process. Organizers can still reassign directly. All times are settings under Takeover requests. Database version 27 (new takeovers table).

= 1.103.3 =
Preview emails (next events announcement and post-event email) now start their subject with [Preview].

= 1.103.2 =
* Tests: the post-event email test no longer checks for a list of banned words (that list is a rule for whoever writes the texts, not a check).

= 1.103.1 =
* The post-event email shows the coming events the same way the Next events announcement does (same code): the next three as cards, later events as short lines.

= 1.103.0 =
* Crew get a separate "Your ticket" email with a scannable code (EC-C for a task, EC-F for a free-entry credit); the door scan accepts them.
* Optional email when a guest is added by hand (wp-admin Roster, crew app Admin tab, /door add Name name@example.com); the ticket goes with the next batch.
* A Holvi buyer added at the desk with an email gets a ticket code (EC-B) and the ticket email.
* "Your ticket is cancelled" email when a guest is removed, or a sign-up or credit is cancelled, after its ticket was emailed.

= 1.102.3 =
Already used in orange with the check-in time at the door scan and in every ticket list (Me tab, crew app, Telegram /me); the ticket page reads only its own row (Holvi gives each ticket its own code).

= 1.102.2 =
Bot: green tick on the Back to the crew app button.

= 1.102.1 =
Ticket page: orange Already used band with the check-in time; a shared order stays green with 'N of M used' until all are used.

= 1.102.0 =
Next events announcement: preview and send times in Settings; write-once translated text fields (bot About/Description) with a global auto-translate switch.

= 1.101.0 =
Post-event email: the day after a night, ticket holders get a thank-you email (if they came) or a we-missed-you email (if they did not), in Finnish and English, with the coming events. Organizers get a preview the day before and can stop it. Off until switched on in wp-admin; switching on never mails a night that is already over.

= 1.100.2 =
Crew app: the Telegram group button and the Crew guides link are on two rows, one under the other.

= 1.100.1 =
The bot's Signed in message has a Back to the crew app button. It signs in the browser where Telegram was pressed and opens the crew app; a waiting crew app tab reloads signed in.

= 1.100.0 =
Telegram sign-in on the crew app now goes through the bot: pressing Telegram opens the bot with a one-time code (30 minutes, tied to this browser by a cookie), the bot sends a Sign in button, and the page signs in by itself. A Telegram account with no crew account signs up in the bot first. The Telegram login widget and popup are removed.

= 1.99.6 =
Sign-up confirmations (Telegram message and email) say you can cancel without it affecting your standing up to 48 hours before the task (the late-cancellation notice set in Settings; left out when it is 0).

= 1.99.5 =
Ticket email: the QR code has the site's icon in the middle, like the web ticket, and stays black on white in mail apps' dark mode (error correction level H, colours also set as background images, and the email asks for light colours only). If the icon does not load, its square stays white and the code still scans.

= 1.99.4 =
Crew app sign-in: the Back link sits above the email field, with an arrow, at normal size and in bold, instead of in small text under the explanation lines.

= 1.99.3 =
Crew app Telegram sign-in: when it fails, the message ends with the reason in brackets (Telegram's error, or the site's HTTP status), so a failed try says which side refused.

= 1.99.2 =
Crew app sign-in: the Email and Telegram buttons are the same width (stacked on phones, side by side on wider screens).

= 1.99.1 =
Crew app sign-in: the Email / Telegram choice shows straight away; the email form (and its Cloudflare check) shows only after pressing Email, or when Telegram's script or the one-time code cannot load.

= 1.99.0 =
Crew members are on the next events announcement by default: everyone with a verified email address is added once at the update (no email sent), and new crew are added when they verify their address. Anyone with a subscriber row in any state, including one who unsubscribed, is never added again. Setting "Add crew members to the newsletter" (on by default). The announcement footer says crew members can stop it with the link and that other crew messages are not affected; the privacy notice says the same.

= 1.98.2 =
Telegram sign-in: the popup address now carries origin=, which Telegram's login page needs (it answered "origin required"); Telegram's own script leaves it out.

= 1.98.1 =
Crew app sign-in: the Email and Telegram buttons are centred, and the Telegram button takes the Email button's look (font, letter case, size, corners); only its colours stay Telegram's.

= 1.98.0 =
Telegram event cards. /events, in a private chat with the bot, shows an organizer the next coming events as picture cards (the event picture, the date, the price or sold out, a short Finnish and English description, and buttons to buy a ticket, listen to the DJ and open the crew app). Confirm posts the same cards in the crew group; Cancel sends nothing. A card without a picture goes out as a text message with the same buttons. An organizer who asks the bot in their own words which events come next gets the same preview. Listed in /help and in the organizer guide.

= 1.97.9 =
After the answer to a sign-up for the next events announcement (in the full form or in the bar), a "Back to top" link takes the visitor back to the top of the page.

= 1.97.8 =
The Cloudflare check of the next events announcement sign-up is only in the full form (e.g. the site footer) when the page has one. Subscribe in the sticky bar copies the address into that form, scrolls to it, and sends it once its check is done, so a visitor no longer gets two checks. A page without the full form keeps the check in the bar.

= 1.97.7 =
The next events announcement bar on the public pages fades in only after the visitor's first interaction with the page (a scroll, wheel, touch, click or key press). An answer to a sign-up from the bar still shows at once.

= 1.97.6 =
* The buttons in the next events announcement (Buy a ticket, Listen to the DJ, the sold-out mark) and the "Do not send this announcement" button in the organizers' preview email are centred.

= 1.97.5 =
* The next events announcement bar at the bottom of the page fades in and out, and stays at the bottom of the screen in Firefox for Android: pages with the bar tell the phone to make the page shorter when the keyboard opens (interactive-widget=resizes-content), and the bar is only moved up by script when a keyboard really covers it (Safari on iOS).

= 1.97.4 =
* The button in the announcement preview email, on its stop page and in the Admin tab now says "Do not send this announcement" (Finnish: "Älä lähetä tätä tiedotetta") instead of "Stop this announcement".

= 1.97.3 =
Next events announcement: each event shows its whole description in both languages, with its paragraphs and line breaks (it was cut after 55 words); the event page link under each card is removed; the Listen to the DJ button shows Finnish and English on two lines.

= 1.97.2 =
Newsletter bar: stays shown while you type in it, and moves above the on-screen keyboard (Firefox on Android drew the keyboard over it).

= 1.97.1 =
Next events announcement form: the words "Your email address" are now inside the email field instead of above it (screen readers still read them).

= 1.97.0 =
Sign in with Telegram on the Crew app (off by default): the sign-in screen asks how you want to sign in, with an Email button and Telegram's own login button. Telegram's OpenID Connect popup is checked on the server (RS256 against Telegram's published keys, issuer, bot ID, age, one-time nonce). Only people already linked to the bot with a confirmed email sign in; others are pointed to the bot's Start. Settings → Telegram bot has the switch, the address to add in @BotFather and the Client ID. The privacy policy mentions it while it is on.

= 1.96.0 =
Settings > Telegram bot: the bot's About and Description, in English and Finnish, with character counts; saving sends each changed text to Telegram (a text Telegram refuses is sent again on the next save). With the group lock on, the bot leaves any other group that adds it; with the lock off a new group still takes the board.

= 1.95.2 =
Documentation: README covers the event cards, the sign-up bar and footer block, the email field name and /say; ROADMAP has a current-state section with the work in progress and the planned work.

= 1.95.1 =
Firefox no longer fills a street address into the next events announcement's email field: the field is now named eventcrew_email (autocomplete=email kept); a page cached before the change still works.

= 1.95.0 =
The next events announcement is drawn as event cards: each newly announced event has its picture, date and time, title, short description in Finnish and then in English, a Buy a ticket button with the price (to the ticket shop), a Listen to the DJ button when the event has the DJ's music page, and a link to the event page. A sold-out event says Sold out instead of the ticket button. Every other coming event is listed under Later with a link to its tickets. The organizers' preview email now shows the announcement as HTML, exactly as subscribers get it. The English version below line and the divider are gone, because each card carries both languages.

= 1.94.0 =
New organizer command /say: in a private chat with the bot, /say followed by a message shows the message as the group will see it, with Confirm and Cancel; Confirm posts it in the crew group. Bold, italics, links, quotes and line breaks are kept. Plain words work too (for example: post in the group: ...). Listed in /help for organizers.

= 1.93.0 =
Footer sign-up form: outline Subscribe button, centred and larger e-mail field, smaller note text. The Cloudflare check now shows only when Cloudflare needs a click (footer form, bar and crew app sign-in). The bar is taller with 40px tap targets and has an i link to the footer form.

= 1.92.0 =
* The next events announcement form is now also a block ("Next events announcement"), so it can be placed in the site footer and other template parts.
* The sign-up bar at the bottom of public pages is thinner and on one line; on a phone the label is hidden.
* Settings: a background image for the sign-up bar, chosen from the Media Library and shown under a dark layer.

= 1.91.0 =
The newsletter is now called the next events announcement (Finnish: tulevien tapahtumien tiedote) everywhere it shows; the /newsletter command and the [eventcrew_newsletter] shortcode keep their names. Each announcement email starts with the line 'English version below ↓'. A sign-up bar is fixed to the bottom of public pages while the announcement is on: one line with the email field, Subscribe and a close button. Closing it hides it for 30 days in that browser; it hides while a full sign-up form (for example in the site footer) is on screen, and it is not shown on the crew app and sign-in page.

= 1.90.0 =
The bot helper sets the organizers' text for the next newsletter from plain words: an organizer tells the bot in a private chat what to add, the bot shows the exact text with a Confirm button, and Confirm saves it as /newsletter does (translated into Finnish and English). /newsletter clear and /newsletter (show) work the same way. Line breaks in the text are kept.

= 1.89.0 =
Newsletter: people join with a public form ([eventcrew_newsletter]) and confirm by email (double opt-in); every mail has a one-click unsubscribe link. It announces the coming events a set number of days before each event (default 7, off until switched on in Settings > Email); organizers get a preview the day before and can stop it. Organizers set a custom text with /newsletter in a private chat or in the crew app's Admin tab; it is translated to the other language when saved and cleared after sending. Each newsletter is in Finnish and English. Sent in batches of 20 through WP-Cron.

= 1.88.0 =
In the group, the bot now remembers for 24 hours what each person wrote to it and its answers, so follow-up questions work there too. Each person has a separate record per group, apart from their private chat; group messages not written to the bot are not kept. The privacy notice, the organizer guide and the README say so.

= 1.87.0 =
The bot sends a private chat to the organizers (email plus a short Telegram message) when the person gives feedback, is unhappy or very happy, or asks for a human; at most once per person per hour, only for people with a confirmed email, with an off switch in Settings > Telegram bot. The privacy notice, the organizer guide and the README describe it.

= 1.86.0 =
Docs: the README, the organizer guide and the privacy notice now describe what the bot's helper knows and keeps: the private chat for 24 hours (the privacy notice wrongly said the text is not stored), the person's roles, the organizer guide for organizers, the official email address and the bot's name. Finnish updated.

= 1.85.0 =
The bot's helper knows the official email address: the contact set in Settings > Privacy. (It already knew the coming events from EventMesh.)

= 1.84.0 =
The bot's helper knows the organizer guide and the infrastructure note when an organizer writes to it in a private chat, so it can answer how-to questions about running the crew. Not in the group chat and not for anyone else.

= 1.83.0 =
/board and the list of tasks for your roles show one day at a time under a fixed row of every date (up to six), the shown day marked ▸; the "Back to the next event" button is gone.

= 1.82.0 =
The bot's helper is told the person's roles and leaves out, without mentioning, anything meant for roles they do not have; it also knows the bot's own name (taken from Telegram when the webhook is installed).

= 1.81.0 =
In a private chat the bot remembers the conversation for 24 hours and uses it to understand follow-up messages; /help and the greeting say so.

= 1.80.3 =
After an update the bot's webhook is replaced in place, never deleted first, so the bot no longer stops hearing messages when Telegram refuses the re-install.

= 1.80.2 =
The bot's answer to a greeting says requests can be written in plain words, not only as commands.

= 1.80.1 =
Fix: when a plain message does not say which task, guest or night it means and more than one fits, the bot asks which one instead of offering one.

= 1.80.0 =
The Telegram bot understands plain messages (optional). Under Settings → Telegram bot → Plain messages, set the address and model of a language model server with an OpenAI-style API, and an API key if it needs one (stored encrypted, never shown again; Test connection checks it). In a private chat, every message that is not a command gets an answer in the language it was written in, using the open tasks, coming events and the text of the site's menu pages (or the pages you choose). The bot can also do what the person could do with a command, with the same permission checks: showing runs straight away, and signing up for a task, leaving one or changing the guest list waits for a tap on Confirm (10 minutes). In the group, it answers only messages meant for the bot that it can answer, and stays silent otherwise. Limits: 20 messages per person and 60 per group per hour (settable). Message text is not stored or logged. Privacy notice, organizer guide and README updated. With the switch off, nothing changes.

= 1.79.2 =
Ticket emails now include the ticket note from Settings (door code and address), under the ticket, like the web ticket.

= 1.79.1 =
Fix: ticket emails could not be sent through FluentSMTP's API providers (Brevo), which read every attachment as a file and threw on the inline QR image. The QR is now drawn in the email as a table of cells, with no attachment and no image to load, and it shows in every mail app. A guest's QR holds a short signed code (EC-G<id>-<signature>) that the door desk accepts, so the guest email stays small. A mail error now marks that ticket as failed and the batch goes on. The ticket mail warning no longer logs the address.

= 1.79.0 =
Ticket emails: after every import, each ticket buyer (and each guest listed with an email address) automatically gets one email with a thank-you and their ticket, Finnish first then English, with the QR code inside the email and a link to the web ticket page (which keeps the clock). Sending runs in batches of 25 through WP-Cron; each row records sent/failed/not sent and the time. The Holvi and Guest list cards show the counts, "Send a test to me" and "Send the rest now" (also retries failed ones). Mailed guest lists: an email address on a line is saved with the guest ("Name <a@b.fi> +2", "Name, a@b.fi", "Name a@b.fi +1"). The door desk accepts the guest ticket QR. Privacy notice and organizer guide updated.

= 1.78.10 =
Security: typing an email in the Telegram bot no longer links that Telegram account to the person straight away. The account is linked only when the emailed confirmation link is clicked; until then the bot does not know the user. When a click moves a person to a new Telegram account, the old Telegram chat is told.

= 1.78.9 =
Crew app Roster screen redesigned: a date bar with previous/next arrows (also on the Door screen), only the crew with a task heading like "DJ · 19:00–22:00 · 2 of 3", one status menu per person that saves on change, Mark all links per task, and "+ Add a person" as a link. The Door list, Guest list and Holvi cards moved to the Door screen.

= 1.78.8 =
Email import: names typed in the mail itself now go to the next event's guest list instead of the ticket buyers. "+2" after a name adds two plus-ones, and lines after a signature line (--) are ignored. A Holvi .xlsx or .csv attachment still goes to the ticket buyers.

= 1.78.7 =
Telegram: /roster has buttons for the next event days and shows when each person signed up. The board shows only the next event; buttons under it show another day, and a back button returns to the next event.

= 1.78.6 =
Admin Door tab: shorter texts. "Open the sales desk", "Send me the link", and "Sent. Check your email, or Telegram if you use the bot."

= 1.78.5 =
Admin Door tab: the Sales desk card now shows "Open the sales desk on this device" first, as its button; "Send me the sales desk link" follows as a link.

= 1.78.4 =
Sign-out lock: from 5 minutes before a task starts, and after the start, crew can no longer sign themselves out (web and Telegram); they are told to tell an organizer. The minutes are a setting (Settings: "Sign-out closes (minutes before the start)", 0 = off). Organizers can still take people off. Tasks without a start time are never locked.

= 1.78.3 =
Ticket buyers: 60 days after the event the name and ticket code are deleted too, not only the email address (rows stay, so the counts of tickets sold and used stay). The public ticket buyer notice and its Finnish version say so.

= 1.78.2 =
Fewer buttons on the crew page's Admin screens: on Roster each card keeps one button (Yes, Update attendance, Redeem, Add guest, Import) and the rest (No, Arrived, Completed, No-show, Mark all, close/reopen free entry, Remove, Save) are text links; the Tasks list's Delete is a text link.

= 1.78.1 =
The door desk's Welcome line greets a person by their first name only. The door list and the database keep the full name.

= 1.78.0 =
Ticket files (.xlsx or .csv) mailed to the import mailbox are imported with one row per ticket, holding the buyer's name, email and ticket code. Ticket file formats (with a Holvi preset), an optional JavaScript decoder per format, and extra sender addresses are set in Settings. Every organizer gets the import notice. The door desk can scan a ticket code to find the buyer. A public privacy notice for ticket buyers ([eventcrew_ticket_privacy]); buyer emails are removed 60 days after the event, and the personal data export and erasure tools now cover ticket buyers.

= 1.77.3 =
A signed-out visitor sees the crew page in their browser's language: Finnish when the browser ranks Finnish above English, English otherwise. Signed-in people keep their own choice.

= 1.77.2 =
Telegram bot: after an update the bot no longer goes silent. When Telegram answers the webhook re-install with "Too Many Requests: retry after N", the plugin waits and tries again (up to 3 times), and an install that still fails is tried again 10 minutes later instead of being marked done.

= 1.77.1 =
Crew page: in admin rows that mix text links, buttons and plain text (the People pager, Save and Cancel), everything now sits on one line instead of the text riding higher than the links.

= 1.77.0 =
Fewer buttons in the Crew app: Sign out, Sign out everywhere, Delete my data, the Crew guide and Organizer guide links, Previous/Next, Cancel, Edit, Open the sales desk on this device (Admin), Reset import, Check the mailbox now and Send open-task email now are now underlined text links. Delete my data has a thick red underline.

= 1.76.0 =
The Crew app's tab bar (Board, Me, Settings, Help, Admin) is now the first thing on the page: a flat menu bar that stays at the top of the screen while you scroll. The Telegram and guide buttons and the "Signed in as" card now sit under it, and the card is narrower. The Admin screens (Roster, Tasks, Door, People, Telegram) are now small text links with a line under the open one, instead of rounded buttons.

= 1.75.0 =
Finnish for the 465 strings that had none: wp-admin, the organizer guide, the privacy notice, Diagnostics and the ticket-buyer import. Roster is now Läsnäolo in Finnish (was Vuorolista).

= 1.74.0 =
After an update, the webhook and the bot's command menus are reinstalled on the next hourly WP-Cron run as well as on the next wp-admin request, so they no longer wait for someone to open wp-admin.

= 1.73.0 =
Five messages were missing from the translation template because a code comment sat inside the translation call: the blocked-admin-request email, the test email, the door welcome email, the privacy erasure message and the free-entry credit message. They are now extracted, and four of them have Finnish (the fifth already had).

= 1.72.0 =
The bot answers any unknown or removed command with the /help list. In a group, a command addressed to another bot is left alone.

= 1.71.0 =
On a narrow phone the Cloudflare check is centred under the email field instead of sitting at the left edge.

= 1.70.0 =
The organizers' message when someone is given a role now says who gave it. Being made an organizer is announced to the other organizers too, with who did it. In wp-admin the giver is the WordPress user's name.

= 1.69.0 =
Settings > Accent colour: the selected tab, sub tab and admin tab, the panel underline and the field outline now use their own colour, so they stay visible when the theme colour (the Crew app page background) is dark. Unset, the accent is the theme colour, as before.

= 1.68.0 =
Sign-in: the form says it also signs up, and an address with no account gets a 'Create your crew account' email. The Turnstile check turns compact in a card narrower than 300px. Settings has a 'Your details' card: the name changes at once; a new email address changes only after the link sent to it is opened, and the old address is told.

= 1.67.0 =
* Telegram has fewer commands. Everyone has /board, /me and /help. /me has buttons for the free-entry ticket, taking over a task, web sign-in, notifications and language. /board in a private chat also lists the tasks of your roles (it replaces /tasks).
* Welcome crew get /roster and /door. /door has buttons for the door list, the guest list and the sales desk. Guests are changed with /door add, /door edit and /door remove.
* Organizers also get /people, with buttons for giving credits, for roles, organizers and passes, and for who may hold each role.
* Every question the bot asks has a Cancel button.
* The old commands (/tasks, /ticket, /replace, /sales, /guest, /gift, /roles, /eligible, /notifications, /language, /cancel and others) are removed. The help, guide and email texts name the new commands and buttons.

= 1.66.0 =
The Crew app (the page set as the sign-up page, Settings, App) opens as a page of its own: the full browser width, without the theme's header, menu and footer, with a "← Website" link at the top, on the app colour. To keep it inside the theme, set the option eventcrew_crew_app_standalone to 0. A page whose address is changed now sends its old address to the new one, so links in emails already sent keep working. Texts that said "crew page" now say "Crew app".

= 1.65.0 =
Guest board: the web page signed out shows the same tasks as the Telegram group board. Signed in, members also see the tasks of their roles; organizers see every task, with a disabled "DJ only" button on roles they do not hold. The role setting is now "Show on the guest board".

= 1.64.1 =
Sales desk: Holvi ticket rows no longer show the +1 buttons. A Holvi ticket admits one person. Tickets sold at the door keep their +1.

= 1.64.0 =
Sales desk: ticket QR scans are taken only inside a time window, by default from 60 minutes before the event starts until the event ends (Settings: "Ticket scans open" and "Ticket scans close"). A scan outside it says when scanning opens or that it closed. A max number of people per event (Settings: "Max people per event"): the header reads "x of n (z max)", x turns green when everyone expected is in, n turns orange from 90% of the max and red at the max, and adding stops at the max. With "Allow going over the max" ticked, adding goes on and the desk shows a warning after every action over the max.

= 1.63.4 =
Sales desk scan: a ticket that was already used tonight now says so in red ("<name>: this ticket was already used tonight."), instead of a small green line on the card.

= 1.63.3 =
Sales desk scan: a person just marked arrived is greeted with "Welcome <name>" in big text on green, instead of "<name> is marked arrived."

= 1.63.2 =
Sales desk scan: errors show in a red box, and the scanned name is bigger (up to 32px) and always on one line.

= 1.63.1 =
Sales desk: in the Switch popup, Login as now comes first and Open on other device second.

= 1.63.0 =
Sales desk: the top buttons are icons with one word under them (Scan, Switch, Add), Check in is a tick that turns green once the person is in, and the Loading texts are now a spinner on the button that was tapped or in the list that is waiting.

= 1.62.0 =
The bot's command menu now shows each person only the commands they may use: group chats, everyone, welcome crew and organizers each get their own list, in English and Finnish.

= 1.61.0 =
Telegram: new /help and /cancel; /me history, /me tickets and /tasks (old names /myhistory, /mytickets, /roletasks still work); /roster takes a date and explains its marks; the door people are called welcome crew everywhere; clearer bot messages with Finnish translations.

= 1.60.2 =
Admin tab People: the older "Give a credit" and "Roles" cards are removed; open a person to give a credit or change their roles.

= 1.60.1 =
wp-admin Add person now stores two or more ticked roles correctly (they were joined into one name, e.g. dj and vj became djvj).

= 1.60.0 =
The crew page's Admin tab has a new Tasks screen with the wp-admin task tools: the tasks of any date with who signed up; adding, editing and deleting a task; creating an event's tasks from the roles; sending the open-task email now; and editing a role's settings (label, emoji, capacity, +1, timing, access, only on events, conditions, description).

= 1.59.0 =
The crew page's Admin tab now has the wp-admin People tools on its People screen: a searchable list of people, adding a person and editing one (email, name, roles, notes), unbanning, the one-time at-risk pass, giving free-entry credits and each person's credit log. Give a credit has no upper limit any more. Making someone an organizer and deleting a person stay in wp-admin.

= 1.58.0 =
The crew page's Admin tab now has the wp-admin Roster tools on its Roster screen: pick any date with tasks; see each person's standing; set any attendance status, or mark a whole task arrived or completed; the door list for the date, with closing and reopening free entry, spending a member's credit and handing it back; the guest list (add, edit, remove); the Holvi ticket buyers, with paste import and a Reset button; and the mailbox check with its last result and a Check the mailbox now button.

= 1.57.1 =
* The reply to a mailed buyer list: its "Open the Roster page" button now opens the Roster screen on the crew page's Admin tab, not wp-admin. Sites with no crew page still get the wp-admin link.

= 1.57.0 =
* Sales desk: a new "📷 Scan" button reads the QR code on a crew ticket or a free-entry ticket with the phone's camera. The popup shows the person's name, a +1 counter that starts at 0 and goes up to what that person may bring, and a "Mark arrived" button. After marking, the camera opens again for the next person. A ticket for another night, a cancelled ticket, someone not on tonight's list, or a QR code that is not an EventCrew ticket gets a message instead.
* The QR code is read by the browser's own reader where the phone has one. Other phones (iPhone Safari) use the bundled decoder from the qr library 0.7.2 (MIT, Paul Miller), which is loaded only when Scan is tapped.
* Tasks have a new "+1 allowed" number: how many guests each person on the task may bring. The sales desk adds the numbers up over a person's tasks that night and shows "allowed +N" on their row.
* Settings > Roles: each role has a "+1 allowed" default, which new tasks of that role copy. The reserved Organize task has its own "+1 allowed" setting, 1 until it is saved.

= 1.56.2 =
The sales desk shows the name of the person the device is logged in as, next to the date.

= 1.56.1 =
Fixed: the logo in the middle of a ticket's QR code could not be seen when the site's logo is white. The QR code now uses the Site Icon (square, made for small sizes) and falls back to the email logo only when no Site Icon is set.

= 1.56.0 =
Roles have a new setting in Settings > Roles, "Only on events": with text in it, new events get a task of that role only when the event title contains that text (upper or lower case does not matter). Empty means every event, as before.

= 1.55.2 =
Crew page: the sign-in form is a card like the rest of the page, and the board's event cards stay inside the page's own box on a desktop screen (the grid no longer widens past it).

= 1.55.1 =
Crew page: the Telegram group and crew guide buttons moved from under the board to the top of the page, above every tab.

= 1.55.0 =
Admin tab: a second row of tabs splits it into four screens, one at a time: Roster (add a person to a task, tonight's crew), Door (sales desk), People (give a credit, roles) and Telegram (post a new board). Roster opens first; after a form is sent, the page comes back on the screen it was sent from. Without JavaScript all screens show one after another, as before. tools/make-pot.php now writes msgctxt for _x() strings.

= 1.54.6 =
Settings: the Save preferences, Sign out everywhere and Delete my data buttons are one size and centred in their cards. Save and Sign out are outlined instead of filled; Delete stays red.

= 1.54.5 =
The Admin tab on the crew page is drawn in the accent colour, so organizers see it at once.

= 1.54.4 =
Bot commands in the Help text and emails (like /roster, or text in backticks) show as code and never break across two lines.

= 1.54.3 =
Tabs look like tabs: each is a raised box with a shadow, the selected one is flat with an accent line under it; on a phone the tab text is smaller so all five fit on one line. The organizer guide link on the Help tab is a centred button.

= 1.54.2 =
The Telegram group button under the board is the same size as the crew guide button, with the Telegram logo and the shorter label Telegram group.

= 1.54.1 =
Board shows 3 columns at 1280 wide (18rem columns, auto-fit). The restricted Sign up button is centred with line-height, which Safari lays out reliably. The date dropdown on the Me tab is no longer yellow. The stylesheet is kept out of W3 Total Cache's combined CSS so each release loads fresh (?ver=).

= 1.54.0 =
The ticket page is redesigned like a crew-board event card: the event's picture at the top with the event name and the full date, then the name, the task, a green VALID or red DISABLED band across the card, a QR code of the ticket's own link with the site logo in the middle, the note and the live clock.

= 1.53.0 =
The automatic sales desk link for organizers now goes only to organizers signed up on that night's Organize task, and carries a second button that opens the crew page's Admin tab. Settings > Reputation & alerts has Telegram and Email checkboxes for it (both on by default; untick both to switch it off).

= 1.52.7 =
Finnish: 22 button, header and label texts shortened to about the length of the English (Sign up is now Ilmoittaudu instead of Ilmoita tehtävään). Sentences are unchanged.

= 1.52.6 =
On a phone, Sign out sits under Suomi | English on the right of the header card, instead of dropping under the name on the left.

= 1.52.5 =
On a computer screen the board shows its event cards side by side (three at 1280px wide). The theme's 645px content column only fitted one; the board alone now widens to the theme's wide size.

= 1.52.4 =
Sign up on a restricted task (Organize, Crew leader, DJ...) is the same height and width as on the other rows, with its word centred; before it was taller with the word at the top.

= 1.52.3 =
Crew page on phones: the wider layout from 1.52.0 now takes effect. WordPress's layout CSS forced the page's side margins back to auto with !important, so the page stayed inside the theme frame's padding; the rule now wins over it.

= 1.52.2 =
Crew page Admin tab: the input fields (Give a credit and the other admin forms) are a light tint of the card's text colour instead of the theme's base colour, which showed as a bright yellow box on a dark theme. Dropdown lists stay dark text on white.

= 1.52.1 =
Crew page: the event picture behind each board card header now shows the upper part of the picture (the faces), not the middle (the chins).

= 1.52.0 =
Crew page: the board's events are cards in columns that fit the screen, each with the event picture behind its header. Each task has an (i) button that opens its description in a bubble; the list at the bottom is gone. The header is one card (name, language, Sign out, standing). The Sales desk button moved off the header: organizers have it on the Admin tab, sales-desk crew on a card at the top of Me. Settings and Help sections are cards. The Telegram group is the main button under the board, the crew guide a second outlined button. Sign-in field, captcha and button are one width and 65px tall. On phones the page uses nearly the full screen width. New Infrastructure text in Settings > Web page, shown to organizers at the end of Help.

= 1.51.1 =
* Crew page, Me tab: My tasks, What you've done and Tickets are cards like the board's events. Each task's ticket is a link on its row under My tasks, so Tickets no longer repeats upcoming and past tasks; it keeps the free-entry credits.
* Crew page, Admin tab: each part (Sales desk, Telegram board, Give a credit, Roles, Add a person to a task, Tonight's crew) is a card. Smaller buttons, outline buttons that really have an outline, fields the full card width, one line per role holder, a scroll box for the task list, one row of buttons per person tonight.
* Crew page: Sign out is an outline button, the same height as the language links.
* Settings > Roles: one role at a time. Each role is a collapsed row (emoji, name, slug, tags); opening it shows its fields. Replaces the 11-column table that needed sideways scrolling.
* The page cache (W3 Total Cache) is emptied once after each plugin update, so visitors do not get pages that link the old stylesheet.

= 1.51.0 =
* The web page board shows each event as a card with its day in the header; task rows show only their time.
* Signed out, the board says once that you sign in first, instead of on every row.
* "What each task involves" lists only roles that have a description.
* The Me tab shows dates as "Sat 7 Nov" instead of 2026-11-07, and tickets are a plain list like My tasks.
* Small grey text is a little stronger, so it reads on a photo or coloured background.
* wp-admin on a phone: Tasks and People stack the form above the list, the Tasks and People lists show every column, the roster's guest list becomes one card per guest, and wide tables scroll sideways.

= 1.50.1 =
* Fix: the "a spot just opened" Telegram message and email are no longer sent when the task has already started (or, for a task with no start time, when its date is over).

= 1.50.0 =
New: the site can read the ticket buyer import mailbox itself, every five minutes, instead of a mail poller on the mail server (Settings > Email > Read the mailbox from this site). It speaks IMAP, or JMAP over HTTPS for hosts that block the IMAP ports, such as InfinityFree; no PHP imap extension is needed. It makes the same sender check (DMARC, or SPF without DMARC) and list checks as the poller, files each message in Imported or Rejected, and shows the last check in Settings. "Check the mailbox now" runs one check at once. It has its own five-minute cron schedule, cleared on deactivation.

= 1.49.1 =
The Telegram board's "New here? Sign up" and "See my info" buttons now start with an emoji (👋 and 👤), like the buttons under them.

= 1.49.0 =
Roles have a new setting, "Show on the Telegram board". A role with it switched off is left off the Telegram group board; its tasks still show on the crew page. Used to keep Random off the board.

= 1.48.1 =
Sales desk: a member already on tonight's crew list now has an Add a task button in the member search, so they can be given another task. Before, they showed with no button.

= 1.48.0 =
Crew page Admin, Add a person to a task: pick an existing member from a list (name and email are now only for a new person), and tick several tasks in one go. The person is found or created once and put on every ticked task; the message says how many of the ticked tasks they were added to.

= 1.47.0 =
Crew leader is an ordinary role. The leader switch, leader slot and leadership settings are gone: Crew leader is a restricted role that only role holders can sign up for. Each role can have conditions (a count per role, or at least N roles done X times each); organizers are told by Telegram and email the first time someone meets them, and the first time someone is given a role. A per-role setting decides who opens the sales desk, gets the door link and marks the roster. /eligible lists who meets the conditions (/leaders still works). Organizers can sign anyone up to any task.

= 1.46.0 =
Tickets are never deleted any more. Undoing a free entry, or swapping it for a task at the door, cancels the redemption instead of removing it. Deleting a task in wp-admin hides it everywhere and takes everyone off it instead of removing the rows. In both cases the ticket link keeps working and shows DISABLED.

= 1.45.0 =
Ticket note: free text (door code, address, ...) shown on every ticket, set in Settings > Web page > Ticket page.

= 1.44.0 =
Restricted roles: tasks for DJ, VJ, Ceremony Leader, Organize and other roles that only the people holding that role can sign up for, by bot (/roletasks), the crew page or the sales desk. Not on the group board. Organizers can add someone who is not a crew member, with an optional email, and choose whether the task counts toward free-entry credit. /roles replaces /allow (which still works) and manages each person's roles.

= 1.43.3 =
The ticket page shows a background image chosen in Settings > Web page > Ticket background (such as the site's background picture) instead of the Site Icon watermark.

= 1.43.2 =
/board sent to the bot in a private chat now answers there with a copy of the board. Its buttons work, and a tap redraws that copy as well as the group board.

= 1.43.1 =
Fix: someone marked out at the sales desk no longer stays arrived on the roster. Their task goes back to Signed up, with the line "signed out by X at HH:MM".

= 1.43.0 =
Sales desk: after signing someone up for a task the popup stays open, so the same person can take more tasks; tasks they already hold show as ✓ Signed up.

= 1.42.1 =
Anyone signed up for a task at the sales desk now gets the signup confirmation with their ticket, by Telegram and email (1.41.1 did this only for the Organize task).

= 1.42.0 =
Finnish and English for everything crew members see: bot messages, emails, the crew page, ticket and sales desk pages. The bot asks Suomi / English the first time in a private chat (/language changes it); the crew page has a Suomi | English switch. The group board stays English. No choice means English. Adds people.language (DB version 17). The Finnish text is machine-drafted and needs a proofread by a Finnish speaker.

= 1.41.1 =
An organizer signed up for the Organize task at the sales desk now gets the signup confirmation with their ticket, by Telegram and email.

= 1.41.0 =
The ticket buyer import mailbox is a setting (Settings > Email > Ticket buyer import by email) instead of an address fixed in the code. While it is empty, the organizer guide, the organizer welcome email and the Roster page leave the email import out.

= 1.40.2 =
Sales desk shows "⏳ Loading…" while it waits for the server (after 0.3 seconds, in the header or inside the open popup), and the member search, task list and Login as list say "Loading…" at once instead of staying blank. The task list and the Login as list open as soon as they are tapped; a failed request replaces "Loading…" with the failure text.

= 1.40.1 =
Sales desk: the −1 / count / +1 buttons for people without a name sit at the right edge of the screen, easier to tap with the thumb.

= 1.40.0 =
New "Organize" task on every event night: only organizers can see it or sign up, with no limit on how many take it. Upcoming nights that already exist get it once, automatically, on the first request after the update. It is left out of open-task mails, the welcome DM and the Telegram group board.

= 1.39.2 =
Organizer guide and organizer welcome email explain the Holvi ticket buyer import by email (import@example.org): who can send, the list format, which event it goes to, and the reply email.

= 1.39.1 =
Fixes the WordPress notice "Translation loading for the eventcrew domain was triggered too early": nothing is translated before init any more.

= 1.39.0 =
Roster: each attendance mark shows who made it and when ("marked by Ana at 21:42"), on the wp-admin Roster and task editor, Telegram /roster and the crew page Admin tab. Telegram and the Admin tab now record who marked. Older marks show only the time. Database schema 16.

= 1.38.3 =
Sales desk: the top part that stays on screen is shorter. The date sits above the count next to the buttons, and the count without a name reads "Without a name: −1 3 +1".

= 1.38.2 =
Sales desk: when the server refuses a member search, the task list or the Login as list, the page shows the error instead of "No member matches" or another empty-list message.

= 1.38.1 =
Sales desk: when two phones press +1 (or −1) at the same moment, both presses now count. Before, one could be lost.

= 1.38.0 =
* The crew web page has an Admin tab for organizers, with four actions: send me the sales desk link (or open it on this device), post a new board in the Telegram group, give a member 1 to 5 credits, and mark tonight's crew Arrived, Completed or No-show.
* Every Admin action is checked on the server against the signed-in person's organizer flag in the database. A request from someone not signed in does nothing. A signed-in member who is not an organizer and sends an Admin action is blocked: their web sessions end, they cannot sign in or sign up for tasks, and organizers get a Telegram DM and an email naming who, when, which action and the IP address.
* wp-admin People: blocked people have a Blocked badge, and the edit panel has an Unban button.

= 1.37.0 =
* Sales desk: a new "📱 Other device" button. "Open on other device" shows a QR code and a Copy link button that log a second phone in to the sales desk as you. "Login as" does the same for another organizer, or for the leader or Welcome crew marked arrived tonight. Each link works once and stops working after 15 minutes. The person is checked again when the link is used.
* The QR code is drawn on the page by the bundled qrcode-generator library 1.4.4 (MIT, Kazuhiko Arase). The page loads nothing from other sites.

= 1.36.5 =
* Sales desk: a new Add choice "Bought on Holvi, not on the list" adds a Holvi ticket buyer who is missing from the imported list, with an optional name and email, and checks them in. The row shows under Holvi tickets, not as a door sale. Nothing is sent to the email.
* Sales desk: "+1" and "−1" buttons count people in without a name. The count is per night and is added to the checked-in total.
* "Reset import" in wp-admin keeps Holvi buyers who were added at the sales desk.

= 1.36.4 =
* The ticket page shows the site logo (the Site Icon) large and faded behind the ticket, as a watermark. The "ticket not found" page has it too. Without a Site Icon the page looks as before.

= 1.36.3 =
* The crew web page has a new Help tab. It shows the welcome text (how tasks, tickets, credits and standing work) without the open tasks. Organizers also see the organizer guide lower in the same tab, with a link to it at the top.
* The welcome message and the organizer guide are easier to read: emoji headings, emoji bullets and more space between lines. The organizer guide starts with a contents list; in the email each entry links to its section.
* The welcome email and DM, and the organizer guide email and DM, say that the guide is also in the Help tab of the crew web page after sign-in, with the link.
* The organizer guide has a link to EventCrew in wp-admin. The member welcome email has the wp-admin button only when the member is an organizer.

= 1.36.2 =
* The welcome email and the organizer guide email have a button to the crew's Telegram group (when its link is known). The organizer guide email also has a button to EventCrew in wp-admin.

= 1.36.1 =
* Web sign-up board: the task rows no longer repeat each role's description under every event. The descriptions are shown once, in the "What each task involves" section at the bottom.

= 1.36.0 =
* New: a welcome message for every member, by email and by Telegram DM, sent once. It explains the tasks (with each role's description), tickets, credits and standing, using the live settings, and lists the open tasks. The DM has a sign-up button for each open task. New members get it when they confirm their email; everyone else gets it from the hourly run, inside the notification hours, 25 people per hour.
* New: an organizer guide for organizers, sent once in addition to the member welcome. The email has an "In short" part and then the details; the DM has the "In short" part. Someone made an organizer later gets it then.
* The welcome message is off until it is switched on in Settings > Reputation & alerts. "Send me the welcome message" on the same tab sends every message to the member with the administrator's email address, without recording it, so it can be checked first.
* New: a "What each task involves" section on the web sign-up board. The Telegram group board, the welcome email and the welcome DM link to it.
* Emails can now have headings (lines starting with "## ").

= 1.35.2 =
* A late cancellation no longer counts against someone who then turns up for another task on the same date. When they are marked arrived, arrived late or completed on that task (Telegram /roster, wp-admin roster or the sales desk), their late cancellations for that date become a plain "cancelled", with no penalty. Signing up alone does not clear it, so signing up and cancelling again does not wash it out. Signing back up to the same task already cleared it. The late-cancellation message sent at cancel time cannot be taken back.

= 1.35.1 =
* Sales desk, Add > Crew member: the search now also lists members who are already on tonight's "Crew working" or "Free-entry credit" list, marked "Already on tonight's list: ..." and without a Choose button. Before, they were left out, so the search said "No member matches" and looked broken.
* Sales desk, Add > Crew member: someone on tonight's "Free-entry credit" list gets a "Take a task instead" button. Signing them up for a task cancels their free-entry ticket, which gives the credit back, and writes "Ticket exchanged against taking a task" in the credit-grant log (as a 0-credit line, so the credit is not counted twice). They are then counted once, on the crew list.
* Sales desk, Add popup: the Ticket / Guest / Crew member buttons now hide once one is chosen. A stylesheet rule kept them visible on every step, which pushed the search results down the screen.

= 1.35.0 =
* Sales desk: an "Add" button in the top bar, next to the count. It opens a popup that asks what to add: Ticket, Guest or Crew member.
* Ticket: a ticket sold at the door, with an optional name. It is checked in at once and listed under the new "Door tickets" list. "Reset import" for Holvi buyers leaves door tickets alone, and the roster page's Holvi list does not show them.
* Guest: a name and how many extra people they may bring, 0 by default. The guest is checked in at once.
* Crew member: search by name or email (members with no credit are listed too), or add a new member with a name and an email. A new member gets one email with a button that confirms the address and signs them in, and a one-person invite link to the crew's Telegram group. Then choose "Use a credit" or "Working tonight", which lists tonight's tasks with a free place; signing up there checks them in and marks them arrived.
* Sales desk: the "Give ticket" search at the bottom of the page is gone; it lives in the Add popup now.
* Sales desk: a guest's count starts at +0, not at the number on the guest list. Each guest row says how many extra people they are allowed ("allowed +2"), the + button stops there, and the expected total counts that allowance.
* Sales desk: free-entry credit rows have no guest buttons any more, like crew rows: a credit lets in one person.
* Telegram /guest help: the examples now say that no +N (or +0) means just the guest, and +N means N extra people.

= 1.34.1 =
* Sales desk: clearer check-in. The row button now says "Check in", and "✓ In" once tapped. The top line says "0 of 12 checked in".
* Sales desk: the − / + count is labelled "guests", so it reads as extra people brought along, not the person. Crew rows no longer have it.

= 1.34.0 =
* New: Holvi ticket buyers on the door list. An organizer can paste the buyer names for a night into the wp-admin Roster page; they appear under "Holvi tickets" on the sales desk.
* New: an import endpoint for the same list by email. An organizer forwards the Holvi buyer list to the import mailbox, a small poller on the mail server checks the sender (DMARC) and sends the names to the site, signed with a secret from Settings. The organizer gets an email saying how many names were added, and to which night. The same email sent twice is not added twice.
* The poller and its install steps are in tools/mail-import/ (not part of the plugin zip).

= 1.33.0 =
* New: the sales desk (door page). A one-time link opens a phone page for the night with four lists: Crew working, Free-entry credit, Guests and Holvi tickets. Each name has an "In" toggle and a +x count, and the page shows a running total and a name filter. "Give ticket" searches for a member and spends their credit, even when free entry is closed. The page refreshes itself every 4 seconds while it is open.
* New: /sales in Telegram sends a fresh sales desk link in a direct message. Organizers can use it at any time; their link works until 06:00 the next morning. The night's leader and Welcome crew can use it from 10 minutes before their task, once they are marked arrived; their link stops working when the event ends.
* New: a "Sales desk" button on the member web page, for the same people.
* New: sales desk links are sent automatically. Organizers get one 30 minutes before the night's first task (or before 17:00 when no task has a start time). The leader and Welcome crew get one when they are marked arrived and their task is near. Both times are set in Settings.
* Marking a crew member "In" at the door also marks their task arrived.
* Fixed: the five-minute link check takes a database lock, so two runs at once cannot send the same link twice.

= 1.32.1 =
* Fixed: the bot could answer the same command two or three times. When the site was slow, Telegram sent the message again before the first answer finished, and each copy was answered. Now only one copy is answered.
* Fixed: two scheduled runs starting at the same moment (WP-Cron and the visitor fallback) could send the same reminder or notice twice. A second run now stops at once.

= 1.32.0 =
* Credits can no longer be spent on a night whose linked EventMesh event stopped selling: the event is not published (for example removed from the Holvi shop), is disabled or hidden, or is sold out. This applies to /ticket, the web page and the wp-admin Roster page.
* Signing up for a task on that night stays open, so crew can still join a sold-out night.
* wp-admin: the Roster page says when the linked event closes a night to credits, and why. The "Close free entry" button still only changes the organizer's own closing.

= 1.31.0 =
* New: **guest list.** Each event night has a list of people let in free who are not crew members, with a number of plus-ones and a note for each guest.
* Telegram: **/guest** shows the next night's list; /guest 1.8. shows another night. Organizers change it: /guest add Anna +2 ; DJ's partner, /guest edit 2 +3 (the number from the list; only the parts given change), /guest remove 2. A date can go before the name or number. The answer always goes to the DM.
* Organizers can change the list. The night's leader and its Welcome crew can see their own night's list.
* wp-admin: the Roster page has a Guest list section under the door list, to add, change and remove guests for the shown date.
* /tickets now lists the night's guests under the crew and credit holders, and the total counts each guest's plus-ones.

= 1.30.0 =
* New: **/tickets** in Telegram shows who has a ticket for an event night: everyone working it and everyone who spent a free-entry credit on it, the same list as the door list on the wp-admin Roster page. The answer always goes to the DM.
* Organizers can see every night. The night's leader and its Welcome crew can see their own night.
* With no date it shows the next event. A date can be given: /tickets 1.8. or /tickets 2026-08-01. Buttons under the list switch to the last past night and the next few.

= 1.29.0 =
* New: **Arrived late.** A new attendance status for someone who did the task but turned up late. On the Telegram /roster each expected person now has three buttons: ✓ completed, ⏰ arrived late, ✗ no-show. It is also in the status list on the wp-admin Roster page.
* Arriving late earns the free-entry credit and counts as a completed task for leader eligibility. In reputation it weighs 90%, between a completion (100%) and finding a replacement (80%); organizers can change the weight in Settings like the other outcomes.

= 1.28.0 =
* Changed: **links are named, not pasted.** Telegram messages no longer carry long raw addresses; each link is a button that says what it opens - "🎟 Show your ticket" and "📅 Add to calendar" on the signup confirmation, "🎟 Show your ticket" on a free-entry ticket, one button per upcoming ticket on /mytickets, "🔑 Sign in on the web" on /web, and "Privacy" on the welcome message.
* Changed: the email-confirmation mail sent during Telegram setup now uses the same branded template as every other mail, with a "Confirm your email" button instead of the raw link. The privacy notice in it, and in the first web sign-in mail, is a named "Privacy" button too.
* The plain-text version of each email still lists the full addresses, since a mail client that shows no HTML has nothing else to click.

= 1.27.0 =
* New: **each role says what the job involves.** A short description per role, edited in Settings ▸ Roles, is shown under the task on the web signup page and as an "About the task" line in the signup confirmation and the 24-hour reminder (Telegram and email). Decorate, Welcome and Clean ship with descriptions taken from the crew guide; roles saved before this version pick those up automatically, and a description you clear stays cleared.
* New: **one shared crew guide link**, set in Settings ▸ Roles. It is never shown as a raw address: it is a "📋 Crew guide" button at the foot of the Telegram group board and in the signup confirmation and reminder DMs, a named button in those emails, and a named link under the web board. Leave it blank to show no guide link.

= 1.26.2 =
* Internal: a structural test now checks that every template only calls methods and reads properties that exist on the class its docblock declares. It is the guard that would have caught the People-page fatal fixed in 1.21.1 - a call to a method that never existed, invisible to a test suite that does not render templates. No change to how the plugin behaves.

= 1.26.1 =
* Fixed: **a free-entry credit could be spent twice.** Both places that redeem one — the /ticket flow and the organizer's Roster page — checked "have they already redeemed this date, and do they have a credit?" and then wrote the redemption as a separate step. Two requests could both be past that check at once: a double-tap, or the bot and the web page a moment apart. Two rows were written, two credits spent against a balance of one, and because the balance is worked out from those rows it simply went negative and clamped at zero — so somebody got in free twice with nothing to show it had happened. The check now lives inside the write itself, which is the same guard the task-signup table has used since v0.5 for exactly this reason.
* The organizer's Roster page now says "They already have free entry for this date" instead of reporting a second redemption it did not make, and sends no second ticket.

= 1.26.0 =
* Sign out now sits at the right-hand end of the line it shares with your name, rather than trailing straight after it. It is also centred against the name instead of sharing a baseline with it, which stopped the button's padding hanging below the text now that it is a real button.
* Section headings on the crew page have a little air above them, so a title is not sitting directly on the button that ended the section before it.

= 1.25.3 =
* Fixed: the account buttons still looked wrong next to the rest of the site — too much padding, the wrong shape. 1.25.2 handed them your theme's button styling but then shrank the label to 85%, and most themes set button padding in pixels: the text got smaller while the padding stayed exactly where it was, so the button became a slab of padding around small text. Overriding one half of a size the theme sets in two places is how that happens. The size override is gone, so they are now simply your theme's buttons — same border, same corners, same padding as every other button on the site.

= 1.25.2 =
* The account buttons now use your theme's own button styling, like every other button on the page already did — Save preferences, Get my ticket, and the board's Sign up and Cancel all wear it. 1.24.0 gave them a hand-made grey surface instead, which was legible but looked like nothing else on the panel. Sign out and Sign out everywhere take the theme's outline style, so they read as the secondary things they are; Delete my data keeps the deep red, layered on top of the theme's button exactly the way Cancel already is.
* Which also settles the legibility problem properly: contrast against the page is the theme's business, decided once by the part that knows what the background is, rather than guessed at here.

= 1.25.1 =
* Fixed: the account buttons added in 1.24.0 could end up with **no background at all**, looking exactly like the bare text they were meant to replace. Their tint and border were written with `color-mix()`, and a value a browser cannot parse takes its whole declaration with it — so where that was unsupported or blocked, the buttons silently lost both. Each now has a plain colour first and the `color-mix()` version second, so there is always a surface and the nicer, theme-following tint applies wherever it is understood.
* The neutral tint was also far too faint to see — 8% over the page's own text colour is close to nothing. It is 20% now, with a stronger border, and the labels are bolder.

= 1.25.0 =
* **A one-minute wait before a sign-in link can be sent again, counted down in the button itself** — "Resend in 47s", then back to "Resend link". Mail takes a moment to arrive, and a second identical link helps nobody; showing the wait rather than silently refusing the press means the button always says what it is doing and when it will be ready. Only a link that actually went starts the clock, so a refused attempt leaves the button ready to try again immediately.
* It is a courtesy, not a rate limit: it lives in the page, so a reload clears it. Cloudflare's check remains the actual guard against anyone hammering the form.

= 1.24.0 =
* **Sign out, Sign out everywhere and Delete my data are now proper buttons**, each with a surface of its own instead of bare underlined text. Three releases tried to make Delete legible by choosing a better colour for the text; none could, because no colour is legible against a background nobody has declared. A button carries its own background and settles it: the neutral two are tinted from the page's own text colour, and Delete carries the deep red under white — the same pairing Cancel already uses, so the two destructive controls now read alike.
* Fixed: **"Resend link" reloaded the spam check and then sent nothing until pressed a second time.** A spent token has to be replaced before anything can go, and the page gave up waiting after four seconds — but a challenge solving from cold after a reset is slower than one that solved during page load, so the first press regularly ran out of patience. It now waits up to ten, and re-arms the widget quietly a second after a link is sent, so by the time you reach for Resend there is usually a token already waiting and it goes on the first press.

= 1.23.4 =
* Fixed, properly this time: **the "Delete my data" link was still nearly unreadable on a dark page.** 1.22.0 shifted it toward the page's text colour but kept too much of the deep red — enough to stay dark, and dark on dark is dark. No fixed red can be legible against a background nobody has declared, and every step toward making one legible is a step away from it being red at all. The wording now takes the page's own text colour, which the theme already chose to be readable against its own background, and the red moves to a thicker underline beneath it, where being dim costs nothing because nobody has to read a line.

= 1.23.3 =
* **Root cause found, at last: the spam check keeps its answer in two places and fills them seconds apart.** Cloudflare hands its token to the page's script the moment the challenge is solved — the tick appears then — and only writes it into the hidden form field a few seconds later. The form submits the field, so a press inside that gap sent nothing at all, was refused for having no token, and showed a tick throughout insisting everything was fine. It also explains the one clue that never fitted: focusing the email box first "fixed" it, because typing spends exactly the seconds the field needed to catch up.
* The request now carries the token explicitly, taking whichever copy exists. So an immediate press works, with no wait and nothing to catch up on.
* This is the bug 1.21.2, 1.22.0, 1.22.1 and 1.23.0 were all circling. 1.23.0 in particular made it likelier, by trusting the copy that lags over the one that leads.

= 1.23.2 =
* Fixed: **a refused spam check took about half a minute to say so.** The page waited fifteen seconds for a token, sent the request without one anyway — which the server refuses outright — then waited another fifteen and sent a second, before finally showing "couldn't verify you're human". Both waits are now four seconds, which is far longer than a challenge that is going to answer needs.
* And it no longer sends a request it knows will be refused. With no token there is nothing to verify, so instead of a round trip and a line in your log it says straight away: "The spam check hasn't finished yet — give it a moment, then try again." The button shows "Checking you're human…" during the wait, so the pause is visible rather than dead air.
* The widget is also no longer reset when it has not produced a token yet — it may be mid-solve or waiting to be clicked, and resetting threw that progress away.

= 1.23.1 =
* Fixed: the spam check re-ran itself the moment the sign-in link was sent, so the tick vanished and the box visibly worked itself out again directly under a message telling you to go and check your inbox. It looked like the form reloading, and it was asking a question nobody had been posed — there is nothing to submit until you press Resend. The widget is now left alone and only refreshed on the next press, where the wait belongs.

= 1.23.0 =
* Fixed: the spam check read one thing and the form sent another. Turnstile writes its result into a hidden field, and that field is what gets submitted — but the page was asking Cloudflare's script instead, which answers for whichever widget it considers first and can come back empty while the field is perfectly well filled in. Both now read the field the form actually sends. This is the likeliest reason a press could fail while the widget showed a tick, and why focusing the email box first appeared to help.
* A refusal with no token at all is now written to Diagnostics. It was the one refusal that returned silently, so an install failing this way showed **nothing**, which reads as "the check never ran" and sent the last two releases after the wrong cause.
* The sign-in wording is shorter: "No password — we email you a link, good for 30 minutes." and "We store your email to run the crew." Both share a narrow centred column under the field, where a longer line breaks into ragged halves. Hints also wrap evenly now on browsers that support it.
* The outcome message no longer sticks around. The redirect after an action put its result in the address bar, so a refresh — or a bookmark, or a shared link — announced "You're signed out" again days later to somebody who was not. Nothing was ever re-run by that; it was a message outliving what it described. It is now cleared from the URL once shown.
* Note on Diagnostics: the recent-activity list holds the **last 20 entries** and nothing else. It is not a time-based log — a busy hour can push a message out within minutes, so read it soon after reproducing something. Everything also goes to your host's own PHP error log, which keeps whatever your host keeps.

= 1.22.1 =
* Fixed: **the spam check could fail on the first press even though the widget showed a tick.** Cloudflare's tokens last about five minutes and are good for one use only, but the widget keeps its tick on screen regardless — so a page left open while somebody reads it, or types their address slowly, sent a token that had quietly gone stale. That is the failure the previous two releases did not catch: the token was there, it was just too old. The page now replaces a token that has been sitting for more than a hundred seconds before sending it, and if the check is refused anyway it fetches a fresh one and retries once by itself, without bothering you.
* Cloudflare's reason for a refusal is now written to Diagnostics. It was being discarded, which left "couldn't verify you're human" as the only evidence — the same message whether the token was stale, already used, or the secret key was simply wrong. **If sign-in is still refusing anyone, look at EventCrew ▸ Diagnostics: the reason is now there in plain words.**

= 1.22.0 =
* The sign-in button now waits for the spam check instead of letting you press it too early. While Cloudflare is still working it reads "Checking you’re human…" and cannot be pressed; it becomes "Email me a sign-in link" the moment there is something to send. 1.21.2 made an early press work by waiting behind the scenes — this makes the wait visible, so nobody spends a press wondering whether anything happened.
* If the challenge never arrives — an ad blocker or privacy extension stopping the script is the usual reason — the button goes live anyway after fifteen seconds rather than staying dead for ever. The press then reaches the server, which explains itself.
* Fixed: **the "Delete my data" link was close to invisible on a dark page.** It was a deep red picked to carry white text on a filled button, which is the wrong colour for bare text on a dark background or over a dark background image. It now shifts with the page's own text colour, so it stays legible and still reads as a warning. Everything else on the page already worked this way; this was the one colour that did not.

= 1.21.2 =
* Fixed: **the sign-in email often did not send on the first press, then worked on the second.** With the Cloudflare captcha switched on, the widget takes a moment to render and solve, and the form read its token the instant the button was pressed — so anyone quick enough submitted an empty one and was refused. The page now waits for the token before sending, and gives up after five seconds and submits anyway, so a blocked or broken challenge script cannot leave you unable to sign in at all.
* Fixed: the page said "Check your inbox for the sign-in link" whether or not one had been sent. A refused captcha or a mistyped address got the same message, so the natural thing to do was go and wait for an email that was never coming, instead of pressing the button again. It now only says that when the link really went.

= 1.21.1 =
* Fixed: **signing out on the crew page did not work.** It answered "please sign in" to somebody who was already signed in and asking to stop being, and left them signed in. Introduced in 1.20.0, which started checking sign-out like every other action but did not give the button the token to pass that check. If you are on 1.20.0, this is the release you want.
* Fixed: "Sign out everywhere" then locked you out for hours. It signed every session out correctly, but then refused new ones for as long as your site's distance from UTC — two or three hours on Finnish time — so a fresh sign-in link signed you in and bounced you straight back out. Two clocks were being compared without converting between them.
* Fixed: a record anonymised by the retention purge kept its live sessions. Anyone still holding that person's cookie stayed signed in, looking at the history of a record that no longer had a name on it. Anonymising now retires those sessions along with everything else.
* Added a check that every form on the crew page carries its token, so the sign-out failure — a form and its handler quietly ceasing to agree — cannot happen again unnoticed.

= 1.21.0 =
* The 📅 moved from an event's date row up to its name row, where it marks the start of each group. On the date row it was labelling something that already looked like a date; on the first row it is the thing the eye finds while scrolling.
* Task rows now lead with the job — "🎈 Decorate · 17:00–18:30 · 1/3". The name is what you are choosing between; the times under one heading are all the same evening, so they separate the rows far less than the work does. Time and how full follow.
* Which also means the role's own emoji starts every task row and no heading has one, so it is clear at a glance which rows do something when tapped and which are just labels.
* A rule now separates the two links at the foot of the board from the tasks above them — they lead out of the board rather than into a slot, and ran on from the last task as though they were one.

= 1.20.0 =
* Security review of the whole plugin. It found no critical or high-severity holes — SQL is uniformly prepared, the signed-link and session tokens are sound, and every admin action already checked both permission and nonce. What it did find was recoverability: a few places where, if something did leak, there was no way to take it back. This release is those.
* **The webhook secret can now be kept out of the URL.** On the admin-ajax fallback door the secret was always sent as a URL parameter as well as a header, which writes it into your server's access log on every update the bot receives. There is a new tick-box to send the header only, and — because the honest answer to "will my host pass the header through?" is "try it" — Settings ▸ Telegram bot now prints the exact header-only request so you can test it with curl before changing anything live. Saving re-installs the webhook for you.
* **And it can be rotated.** A new "Rotate the webhook secret" button generates a fresh one and re-installs. Anything that can post a valid update to the webhook can claim to be any member of your crew, organizers included, so a secret that may have been seen — in a log, a screenshot, a support ticket — is worth replacing. Taking it out of the URL does not remove what is already in the logs; rotating does.
* **"Sign out everywhere"**, on your crew page under Settings. Signing in leaves a browser signed in for 30 days, and until now signing out only cleared the cookie on the device in front of you. This retires every session at once — another browser, a shared computer, a phone left somewhere — including the one you press it from.
* Fixed: signing out could be triggered by a form on another site. Harmless in effect, but it now requires the same token every other action does.
* The Mobile app ▸ Signup page setting quietly decided something else: with no page picked, the "manage your data" link at the foot of every email fell back to one that never expires, instead of a one-time link good for 30 minutes. The field now says so, and warns if you have a signup page that has not been picked.
* Also: the self-service manage page and the door-ticket page now tell caches and search engines not to store them; and the retired open-task opt-in columns, unwritten since v0.6 and unread by anything, are dropped from the database.
* Database: adds one column (`sessions_valid_from`), drops two, applied automatically on upgrade.

= 1.19.0 =
* The Telegram board now reads as a programme rather than a list of buttons. Each event is introduced by two heading rows — its name, then its date — and its tasks follow underneath, so with several events open you can see at a glance where one ends and the next begins. The headings are inert: tapping one does nothing.
* Task buttons lead with the time: "17:00–18:30 · Clean 1/3". The time is what you scan for when you are looking for a slot you can make, and the rows under one heading are in time order, so the evening's shape is readable down the column. The date is gone from the buttons — the heading two rows up carries it.
* Headings appear even when only one event is open. The board used to drop them in that case, which meant it changed shape as soon as a second event opened.
* Overlapping slots can now be booked. Two tasks whose times run into each other were refused; they are not any more, on the board, on the web page, and when covering for someone. Whether you can do both is your call — the plugin was answering it for you from the clock alone, and turning away crew who could have covered both.
* A "Refresh the board" button under Settings ▸ Telegram bot. The board still redraws itself whenever anything on it changes, so this is for the times that cannot cover: after an upgrade that lays the board out differently, or to see a change land without waiting for the next hourly check. It edits the board where it sits, so nobody in the group is notified, and it tells you whether it worked — including when the board has been deleted from the group and needs a fresh /board.

= 1.18.0 =
* GDPR: a new Settings ▸ Privacy tab asks who is answerable for the crew's data — the organisation, a contact address, and a data protection officer if you have designated one — and writes the privacy notice for you from it. The notice describes what this install actually does: Telegram and Cloudflare appear in it only if you use them.
* A public notice page: the new `[eventcrew_privacy]` shortcode and matching block, with a one-click button under the new tab that creates the page and selects it. The same text is offered to WordPress as suggested privacy-policy content under Settings ▸ Privacy.
* Signing up now says what happens to your address, at the moment you hand it over: a line under the sign-in field linking to the notice, the same line in the bot's onboarding, and in the first sign-in email. No tick box — signing up is what makes the processing necessary, and a box consenting to something we would do anyway would describe the basis wrongly rather than strengthen it.
* A retention period you can set, and optionally have enforced. Left off, it is a promise you keep by hand. Switched on, the hourly heartbeat anonymises records that have been dormant past it: email, name, notes and any Telegram link are erased for good, while the attendance rows stay, so "nine people worked that night" survives with nobody attached to it. Organizers and crew leaders are never touched while they hold the role.
* EventCrew now answers WordPress's Tools ▸ Erase Personal Data as well as the export it already answered, so a request that arrives by email can be handled from the screen WordPress provides for it.
* Fixed: opening anyone in the People editor was a fatal error — the screen called a method that does not exist. It also reported an "opted in" date from a column nothing has written since the opt-in model was replaced by per-type opt-outs in v0.6; it now shows the setting that actually governs the send.
* Fixed: deleting a person left their sign-in tokens and their notification history behind, so an "erased" account kept a live magic link in any email they still had. Erasing is now one routine shared by the self-service Delete, the organizer's Delete, and the WordPress eraser.
* Database: adds one column (`anonymized_at`), applied automatically on upgrade.

= 1.17.1 =
* Fixed: lead times were counted back from midnight of the task's day rather than from the task itself, so a "48 hours before" notice for a task at 17:00 went out 65 hours before it. Both the open-task call and the board re-post now count from when the day's work actually starts. A date whose tasks have no times recorded still counts from its midnight, which is the only thing it can mean.

= 1.17.0 =
* Notifications no longer arrive in the middle of the night. There is a new "Start sending at" setting under Settings ▸ Reputation & alerts, defaulting to 09:00: scheduled messages wait for that hour and go out over the twelve hours after it. Before this, a message left the moment it came due, so a task starting at three in the morning produced a reminder at three in the morning.
* Held: task reminders, the open-task call, standing notices, the board re-post and crew-leader candidate notices. Never held: anything answering something a person just did — a signup confirmation, a freed slot, a ticket.
* Also never held: a reminder for a task that starts before the window next opens. Waiting until nine for something that begins at eight would be worse than the odd hour.

= 1.16.0 =
* Security: the signup page is no longer cacheable while somebody is signed in. Crew are not WordPress users, so their session cookie is one a caching plugin has never heard of — and a plugin that only skips caching for logged-in WordPress users would store a signed-in page and serve it to the next visitor, complete with that person's name, standing, ticket links and the token that deletes their account. If you run any page cache, this release matters.
* Uninstalling with "delete my data" enabled now removes every setting and temporary value the plugin ever wrote, by prefix. About thirty were being left behind, including the Telegram bot token and the Turnstile secret.
* Your crew can now get their data out, not just delete it: EventCrew answers WordPress's Tools ▸ Export Personal Data with a person's profile, every task they took and how it went, and any free entry they used.

= 1.15.5 =
* Fixed: a task's slot count and button now always sit to the right of its name and time, on every screen size. They were laid out in a way that moved them to a new line whenever they did not quite fit, which on a phone was most of the time; they are now in a column of their own that cannot move, and a long name wraps within its own space instead.

= 1.15.4 =
* A task's slot count now sits above its button rather than beside it, which makes that whole block about a third narrower — so rows stay on one line on a phone instead of wrapping.

= 1.15.3 =
* Fixed: on a phone, a task's slot count and its button often broke onto a second line when there was room for them, and one could end up stranded away from the other. The task's name and time can now give way, so rows stay on one line far more often — and when a row genuinely is too narrow, the count and the button move down together and stay against the right edge.

= 1.15.2 =
* Fixed: on a sign-in form with a captcha, the email you typed was centred inside its own box. The form is centred on the page; the text in the field is not.
* Removed dead code left by the previous two releases.

= 1.15.1 =
* Fixed: the board worked out for itself whether the standing gate was holding someone, rather than asking the code that actually decides it. Change the gate's behaviour and the board would have gone on showing the old answer — offering a Sign up button the signup would then refuse, or hiding one that would have worked. Both checks now come from the single rulebook.

= 1.15.0 =
* The board now says when you cannot take a task, on the row, instead of letting you press Sign up and refusing afterwards. A crew-leader slot reads "Crew leaders only", and someone held by the standing gate sees "On hold — talk to an organizer".
* New "What you've done" section on the Me tab: your last five finished tasks and how each turned out. Your standing is calculated from exactly this, so it can now be checked rather than taken on trust.
* An unrated member is "Beginner" rather than "New".
* Accessibility: signing up or cancelling no longer throws keyboard focus back to the top of the page. Focus lands on whatever replaces the button you pressed.

= 1.14.8 =
* The slot dots are filled shapes rather than outlined ones, so they are properly round. An outline gives the screen two curved edges to draw across twelve pixels, which is not enough for either of them; a filled shape has one. An empty slot is now a faint dot and a taken one a green dot.

Note for anyone whose changes to this plugin's appearance do not seem to take effect: if your site uses a CSS minifier or optimiser, it combines stylesheets into a bundle of its own and the plugin's version number never reaches the browser. Purge that plugin's cache after updating, or exclude eventcrew.css from its aggregation.

= 1.14.7 =
* The slot dots are round, and identical to each other. They were sized in a unit that worked out to 13.6 pixels, which no screen can draw, so each one was rounded off slightly differently from its neighbour. They are a plain 12 pixels now.
* An empty slot's ring is stronger, so it carries the same visual weight as a filled one — the two should differ in state, not in how much they stand out.

= 1.14.6 =
* The signup page works on a dark theme without being told it is on one. Borders, text and the empty slot rings now take their colour from the page itself rather than assuming a light background, so a dark theme, a classic theme, or a dark section of a light page all look right. There is deliberately no light/dark setting: it would be one more thing to keep in step with your theme, and the page can simply look instead.

= 1.14.5 =
* The slot dots are bigger and drawn at full strength — an empty slot is a solid green ring, a filled one is that ring filled in. Before they were hairline circles at half opacity, which at that size amounted to nothing.

= 1.14.4 =
* Your standing and credits ("New · 9 credits"), and the "How your score works" explainer, now sit under your name above the tabs — visible from every tab instead of only from Me.
* The Me tab is split into two named sections, My tasks and Tickets, each shown even when empty so you can tell the difference between having nothing and the page having lost something. Spending a free-entry credit moved under Tickets, which is what it produces.
* Your tickets are no longer hidden behind a collapsed disclosure.

= 1.14.3 =
* The Me tab reads in the order asked for: standing and credits, then your next tasks, then spending a free-entry credit, then your tickets. 1.14.1 moved the credit-spending control above the task list as well, which was not the request.
* The slot dots use a vivid green, so a filled slot is obvious at a glance instead of a dark speck.

= 1.14.2 =
* Fixed: on some sites the signup page arrived with none of its styling — no centring, no aligned buttons, no slot dots, and the browser's own focus ring round the tabs. The stylesheet was only registered while WordPress was preparing the page's assets, so a page rendered by any other route (a site-editor template part, a page builder, the block's preview) asked for a stylesheet that had never been registered, and asking for an unregistered stylesheet fails silently. It is now registered wherever it is first needed.
* Fixed: Sign out sat far below your name instead of beside it.
* Fixed: focusing a tab drew a box around it. The underline thickens instead.

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

= 1.140.72 =
No settings change.

= 1.140.71 =
No settings change.

= 1.140.70 =
No settings change.

= 1.140.69 =
No settings change.

= 1.140.68 =
No settings change.

= 1.140.67 =
No settings change.

= 1.140.66 =
No settings change.

= 1.140.65 =
No settings change.

= 1.140.64 =
No settings change.

= 1.140.63 =
No settings change.

= 1.140.62 =
No settings change.

= 1.140.61 =
No settings change.

= 1.140.60 =
No settings change.

= 1.140.59 =
No settings change.

= 1.140.58 =
No settings change.

= 1.140.57 =
No settings change.

= 1.140.56 =
No settings change.

= 1.140.55 =
No settings change.

= 1.140.54 =
No settings change.

= 1.140.53 =
No settings change.

= 1.140.52 =
No settings change.

= 1.140.51 =
No settings change.

= 1.140.50 =
No settings change.

= 1.140.49 =
No settings change.

= 1.140.48 =
No settings change.

= 1.140.47 =
No settings change.

= 1.140.46 =
No settings change.

= 1.140.45 =
No settings change.

= 1.140.44 =
No settings change.

= 1.140.43 =
No settings change.

= 1.140.42 =
No settings change.

= 1.140.41 =
No settings change.

= 1.140.40 =
No settings change.

= 1.140.39 =
No settings change.

= 1.140.38 =
No settings change.

= 1.140.37 =
No settings change.

= 1.140.36 =
No settings change.

= 1.140.35 =
No settings change.

= 1.140.34 =
No settings change.

= 1.140.33 =
No settings change.

= 1.140.32 =
No settings change.

= 1.140.31 =
No settings change.

= 1.140.30 =
No settings change.

= 1.140.29 =
No settings change.

= 1.140.28 =
No settings change.

= 1.140.27 =
No settings change.

= 1.140.26 =
No settings change.

= 1.140.25 =
No settings change.

= 1.140.24 =
No settings change.

= 1.140.23 =
No settings change.

= 1.140.22 =
No settings change.

= 1.140.21 =
No settings change.

= 1.140.20 =
No settings change.

= 1.140.19 =
No settings change.

= 1.140.18 =
No settings change.

= 1.140.17 =
No settings change.

= 1.140.16 =
No settings change.

= 1.140.15 =
No settings change.

= 1.140.14 =
No settings change.

= 1.140.13 =
No settings change.

= 1.140.12 =
No settings change.

= 1.140.11 =
No settings change.

= 1.140.10 =
No settings change.

= 1.140.9 =
No settings change.

= 1.140.8 =
No settings change.

= 1.140.7 =
No settings change.

= 1.140.6 =
No settings change.

= 1.140.5 =
No settings change.

= 1.140.4 =
No settings change.

= 1.140.3 =
No settings change.

= 1.140.2 =
No settings change.

= 1.140.1 =
No settings change.

= 1.140.0 =
No settings change.

= 1.139.0 =
No settings change.

= 1.138.0 =
No settings change.

= 1.137.1 =
No settings change.

= 1.137.0 =
No settings change.

= 1.136.6 =
No settings change.

= 1.136.5 =
No settings change.

= 1.136.4 =
No settings change.

= 1.136.3 =
No settings change.

= 1.136.2 =
No settings change.

= 1.136.1 =
No settings change.

= 1.136.0 =
No settings change.

= 1.135.0 =
No settings change.

= 1.134.1 =
No settings change.

= 1.134.0 =
No settings change.

= 1.133.0 =
No settings change.

= 1.132.0 =
No settings change.
