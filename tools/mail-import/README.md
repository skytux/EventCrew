# EventCrew mail import

`eventcrew-mail-import.py` reads the lists of Holvi ticket buyers that
organizers email to `import@example.org` and sends each list to the
EventCrew site. The site puts the names on the door list of the next event.

It runs on the mail server (or any machine that can reach the mailbox over
IMAP and the site over HTTPS), every 5 minutes, from a systemd timer.

Since EventCrew 1.50.0 the site can read the mailbox itself (Settings > Email >
"Read the mailbox from this site", over IMAP or JMAP), and then this poller is
not needed. It makes the same sender and list checks. Run one of the two, not
both.

## What one run does

1. If `PING_URL` is set, it fetches that URL.
2. It logs in to the mailbox and reads every unread message in `IMAP_FOLDER`.
3. For each message:
   - It reads the `Authentication-Results` header that the mail server added.
     The domain of the `From` address must have `dmarc=pass`. If the header has
     no DMARC result at all, `spf=pass` for that domain is accepted instead.
     Otherwise the message is moved to `Rejected`.
   - It takes the names from a `.txt` or `.csv` attachment (first column of
     each row), or else from the plain-text body.
   - It sends them to the site as JSON `{"from", "text", "message_id",
     "sent_at"}`, with the header
     `X-EventCrew-Signature: sha256=<HMAC-SHA256 of the body>`, signed with
     `IMPORT_SECRET`. `sent_at` is the Unix time of sending. The site refuses
     (401) a body whose `sent_at` is more than 5 minutes from its own clock,
     so keep this machine's clock right (NTP).
   - The site checks that the sender is an organizer, adds the names and mails
     the organizer a confirmation.
   - Site answered 200: the message is marked read and moved to `Imported`.
     Site answered 400, 403 (not an organizer) or 409 (no upcoming event): it
     is marked read and moved to `Rejected`.
     Anything else (network error, 401 wrong secret, 5xx): the message stays
     unread where it is, and the next run tries it again.
4. The same message sent twice adds nothing the second time: the site skips
   every name that is already on that night's guest list. (It still mails the
   organizers a confirmation that says the names were skipped.)

The site is hosted on InfinityFree, which answers the first request with a
JavaScript page that sets a `__test` cookie. The script solves that page the
same way a browser would and repeats the request with the cookie.

Each run prints one line per message. Read them with
`journalctl -u eventcrew-mail-import`.

## Install

Needs Python 3.9 or newer and the `cryptography` package
(`dnf install python3-cryptography` or `apt install python3-cryptography`).

1. Create the mailbox `import@example.org` in Stalwart.
2. In wp-admin go to EventCrew > Settings > Email. Under "Ticket buyer import
   by email", copy the endpoint URL and the secret.
3. Create a system user that owns nothing else:

   ```
   sudo useradd --system --no-create-home --shell /usr/sbin/nologin eventcrew-import
   ```

4. Copy the script:

   ```
   sudo install -d /opt/eventcrew-mail-import
   sudo install -m 0755 eventcrew-mail-import.py /opt/eventcrew-mail-import/
   ```

5. Write the settings, starting from the example, and fill in the mailbox
   password, `IMPORT_URL` and `IMPORT_SECRET`:

   ```
   sudo install -m 0640 -o root -g eventcrew-import eventcrew-mail-import.env.example /etc/eventcrew-mail-import.env
   sudoedit /etc/eventcrew-mail-import.env
   ```

   Look at the `Authentication-Results` header of a message that reached the
   mailbox. The first word after the colon is the mail server's authserv-id
   (for example `mx.example.org`). Put it in `AUTHSERV_ID`, so that only the
   header your own server added is trusted.

6. Run it once by hand and read the output:

   ```
   sudo -u eventcrew-import /usr/bin/python3 -I /opt/eventcrew-mail-import/eventcrew-mail-import.py
   ```

7. Install and start the timer:

   ```
   sudo install -m 0644 eventcrew-mail-import.service eventcrew-mail-import.timer /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now eventcrew-mail-import.timer
   ```

## When the secret changes

"Generate a new secret" in Settings makes the old secret stop working at once.
Put the new one in `IMPORT_SECRET` in `/etc/eventcrew-mail-import.env`. Until
then every message gets 401 and stays unread in the mailbox; nothing is lost.

## Mail from an organizer's own example.org address

A message an organizer sends through the same Stalwart server (logged in, on
the submission port) may get no DMARC or SPF result in its
`Authentication-Results` header, because it never came in from outside. Such a
message is moved to `Rejected`. Test this with a real message before relying on
it.
