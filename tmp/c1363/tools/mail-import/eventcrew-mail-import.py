#!/usr/bin/env python3
"""Reads the ticket buyer lists organizers mail in and sends them to EventCrew.

One run:
  1. GETs PING_URL, if set (a dead-man's-switch monitor sees the poller ran).
  2. Logs in to the IMAP mailbox and reads every UNSEEN message in IMAP_FOLDER.
  3. For each message:
     - checks the mail server's Authentication-Results header: the From domain
       must have dmarc=pass, or spf=pass when there is no DMARC result at all;
     - takes the list: a .txt or .csv attachment (first column of each row),
       otherwise the text/plain body;
     - POSTs {"from", "text", "message_id"} as JSON to IMPORT_URL, signed with
       X-EventCrew-Signature: sha256=<HMAC-SHA256 of the body under IMPORT_SECRET>;
     - marks it seen and moves it to IMPORTED_FOLDER when the site took it, or
       to REJECTED_FOLDER when the site or the check above refused it.
     A message that could not be delivered (network error, site error, wrong
     secret) is left unseen in place, so the next run tries it again.

The site checks again that the sender is an organizer, so this script never
needs to know who the organizers are.

Settings come from the environment (systemd's EnvironmentFile) or from the env
file named by --env (default /etc/eventcrew-mail-import.env). See README.md.

Needs Python 3.9+ and the "cryptography" package (for the InfinityFree
aes.js cookie check, which the site's host puts in front of every request).
"""

import argparse
import csv
import email
import email.policy
import email.utils
import hashlib
import hmac
import imaplib
import io
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.request

DEFAULT_ENV_FILE = '/etc/eventcrew-mail-import.env'
USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) eventcrew-mail-import'
TIMEOUT = 60

# What came of one message.
IMPORTED = 'imported'
REJECTED = 'rejected'
RETRY = 'retry'


def log(message):
    print(message, flush=True)


# --- settings -----------------------------------------------------------------

def read_env_file(path):
    """KEY=value lines; '#' comments and blank lines ignored; quotes stripped."""
    values = {}
    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'':
                value = value[1:-1]
            values[key.strip()] = value
    return values


def load_settings(env_file):
    settings = {}
    if env_file and os.path.exists(env_file):
        settings.update(read_env_file(env_file))
    # The process environment wins, so systemd's EnvironmentFile and a manual
    # override on the command line both work.
    for key, value in os.environ.items():
        settings[key] = value

    missing = [k for k in ('IMAP_HOST', 'IMAP_USER', 'IMAP_PASSWORD', 'IMPORT_URL', 'IMPORT_SECRET')
               if not settings.get(k)]
    if missing:
        raise SystemExit('Missing settings: ' + ', '.join(missing))

    settings.setdefault('IMAP_PORT', '993')
    settings.setdefault('IMAP_FOLDER', 'INBOX')
    settings.setdefault('IMPORTED_FOLDER', 'Imported')
    settings.setdefault('REJECTED_FOLDER', 'Rejected')
    settings.setdefault('AUTHSERV_ID', '')
    settings.setdefault('PING_URL', '')
    return settings


# --- sender check ---------------------------------------------------------------

def from_address(message):
    _name, address = email.utils.parseaddr(str(message.get('From', '')))
    return address.strip().lower()


def domain_of(address):
    return address.rsplit('@', 1)[1] if '@' in address else ''


def results_header(message, authserv_id):
    """The Authentication-Results header our own mail server added.

    The receiving server puts its header on top, above anything the sender
    wrote, so the first one is the one to trust. With AUTHSERV_ID set, only a
    header that names that server counts.
    """
    for header in message.get_all('Authentication-Results') or []:
        text = ' '.join(str(header).split())
        if not authserv_id:
            return text
        if text.split(';', 1)[0].strip().lower() == authserv_id.lower():
            return text
    return ''


def _results(header, method):
    """[(result, {property: value}), ...] for each method=result clause."""
    found = []
    for clause in header.split(';')[1:]:
        clause = clause.strip()
        match = re.match(method + r'\s*=\s*(\w+)(.*)', clause, re.IGNORECASE)
        if not match:
            continue
        props = {}
        for key, value in re.findall(r'([\w.]+)\s*=\s*"?([^\s";]+)"?', match.group(2)):
            props[key.lower()] = value.lower()
        found.append((match.group(1).lower(), props))
    return found


def sender_is_authenticated(message, authserv_id=''):
    """(ok, reason). dmarc=pass for the From domain; spf=pass when no DMARC ran."""
    address = from_address(message)
    domain = domain_of(address)
    if not domain:
        return False, 'no From address'

    header = results_header(message, authserv_id)
    if not header:
        return False, 'no Authentication-Results header from our mail server'

    dmarc = _results(header, 'dmarc')
    if dmarc:
        for result, props in dmarc:
            if result == 'pass' and props.get('header.from', domain) == domain:
                return True, 'dmarc=pass'
        return False, 'dmarc did not pass for ' + domain

    for result, props in _results(header, 'spf'):
        mailfrom = props.get('smtp.mailfrom', '')
        # smtp.mailfrom is a whole address or, for some servers, only a domain.
        if result == 'pass' and (domain_of(mailfrom) or mailfrom) == domain:
            return True, 'spf=pass (no DMARC result)'
    return False, 'neither dmarc nor spf passed for ' + domain


# --- the list -------------------------------------------------------------------

def _decode(part):
    payload = part.get_payload(decode=True) or b''
    charset = part.get_content_charset() or 'utf-8'
    try:
        return payload.decode(charset, errors='replace')
    except LookupError:
        return payload.decode('utf-8', errors='replace')


def first_column(text):
    """The first column of a CSV, one value per line. Semicolons count too:
    a spreadsheet saved as CSV with a Finnish locale uses them."""
    sample = text[:4096]
    delimiter = ';' if sample.count(';') > sample.count(',') else ','
    lines = []
    for row in csv.reader(io.StringIO(text), delimiter=delimiter):
        if row:
            lines.append(row[0])
    return '\n'.join(lines)


def list_text(message):
    """The names: a .txt/.csv attachment if there is one, else the text body."""
    for part in message.walk():
        filename = (part.get_filename() or '').lower()
        if filename.endswith('.csv'):
            return first_column(_decode(part))
        if filename.endswith('.txt'):
            return _decode(part)

    for part in message.walk():
        if part.get_content_type() == 'text/plain' and part.get_content_disposition() != 'attachment':
            return _decode(part)
    return ''


# --- talking to the site ----------------------------------------------------------

def solve_challenge(body):
    """The __test cookie the InfinityFree aes.js page would set in a browser."""
    from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

    key, iv, data = re.findall(r'toNumbers\("([0-9a-f]+)"\)', body.decode('latin-1'))[:3]
    decryptor = Cipher(algorithms.AES(bytes.fromhex(key)), modes.CBC(bytes.fromhex(iv))).decryptor()
    return '__test=' + (decryptor.update(bytes.fromhex(data)) + decryptor.finalize()).hex()


def is_challenge(body):
    return b'aes.js' in body and b'toNumbers' in body


def http(url, data=None, headers=None, cookie=''):
    request = urllib.request.Request(url, data=data, method='POST' if data is not None else 'GET')
    request.add_header('User-Agent', USER_AGENT)
    for key, value in (headers or {}).items():
        request.add_header(key, value)
    if cookie:
        request.add_header('Cookie', cookie)
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


class Site:
    def __init__(self, url, secret):
        self.url = url
        self.secret = secret.encode('utf-8')
        self.cookie = ''

    def post(self, payload):
        """(status, decoded JSON or {}) for one list."""
        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        signature = 'sha256=' + hmac.new(self.secret, body, hashlib.sha256).hexdigest()
        headers = {'Content-Type': 'application/json', 'X-EventCrew-Signature': signature}

        status, answer = http(self.url, body, headers, self.cookie)
        # The host answers with a JavaScript page that sets a cookie instead of
        # passing the request on. Solve it once; the cookie is kept for the run.
        if is_challenge(answer):
            self.cookie = solve_challenge(answer)
            status, answer = http(self.url, body, headers, self.cookie)

        try:
            decoded = json.loads(answer.decode('utf-8'))
        except ValueError:
            decoded = {}
        return status, decoded if isinstance(decoded, dict) else {}


def outcome_for(status):
    """What to do with a message after the site answered with this status."""
    if status == 200:
        return IMPORTED
    # 400 unreadable, 403 not an organizer, 409 no upcoming event: sending the
    # same mail again will not help.
    if status in (400, 403, 409):
        return REJECTED
    # 401 (wrong secret), 5xx, a challenge page that would not clear: our side
    # or the site's, not the mail's. Try again next run.
    return RETRY


def process(message, site, authserv_id=''):
    """(outcome, log line) for one parsed message."""
    sender = from_address(message)
    ok, reason = sender_is_authenticated(message, authserv_id)
    if not ok:
        return REJECTED, 'rejected %s: %s' % (sender or '?', reason)

    text = list_text(message)
    if not text.strip():
        return REJECTED, 'rejected %s: no text/plain body and no .txt/.csv attachment' % sender

    payload = {
        'from': sender,
        'text': text,
        'message_id': str(message.get('Message-ID', '')).strip(),
    }
    try:
        status, answer = site.post(payload)
    except (urllib.error.URLError, OSError, ValueError) as error:
        return RETRY, 'will retry %s: %s' % (sender, error)

    outcome = outcome_for(status)
    return outcome, '%s %s: HTTP %d %s' % (outcome, sender, status, json.dumps(answer, ensure_ascii=False))


# --- the mailbox -------------------------------------------------------------------

def quote_folder(name):
    return '"' + name.replace('\\', '\\\\').replace('"', '\\"') + '"'


def ensure_folder(imap, name):
    imap.create(quote_folder(name))  # "already exists" is fine; ignore the answer


def move(imap, uid, folder):
    imap.uid('STORE', uid, '+FLAGS', '(\\Seen)')
    capabilities = getattr(imap, 'capabilities', ())
    if 'MOVE' in capabilities:
        imap.uid('MOVE', uid, quote_folder(folder))
        return
    imap.uid('COPY', uid, quote_folder(folder))
    imap.uid('STORE', uid, '+FLAGS', '(\\Deleted)')
    imap.expunge()


def ping(url):
    if not url:
        return
    try:
        http(url)
    except (urllib.error.URLError, OSError, ValueError) as error:
        log('ping failed: %s' % error)


def run(settings):
    ping(settings['PING_URL'])

    site = Site(settings['IMPORT_URL'], settings['IMPORT_SECRET'])
    imap = imaplib.IMAP4_SSL(settings['IMAP_HOST'], int(settings['IMAP_PORT']),
                             ssl_context=ssl.create_default_context())
    try:
        imap.login(settings['IMAP_USER'], settings['IMAP_PASSWORD'])
        for folder in (settings['IMPORTED_FOLDER'], settings['REJECTED_FOLDER']):
            ensure_folder(imap, folder)

        status, _ = imap.select(quote_folder(settings['IMAP_FOLDER']))
        if status != 'OK':
            raise SystemExit('Could not open folder ' + settings['IMAP_FOLDER'])

        status, data = imap.uid('SEARCH', None, 'UNSEEN')
        uids = data[0].split() if status == 'OK' and data and data[0] else []

        for uid in uids:
            uid = uid.decode()
            # BODY.PEEK leaves \Seen alone: a message only becomes seen once
            # it has been dealt with, so a crash mid-run loses nothing.
            status, fetched = imap.uid('FETCH', uid, '(BODY.PEEK[])')
            raw = next((part[1] for part in fetched or [] if isinstance(part, tuple)), None)
            if status != 'OK' or raw is None:
                log('could not fetch message %s' % uid)
                continue

            message = email.message_from_bytes(raw, policy=email.policy.default)
            outcome, line = process(message, site, settings['AUTHSERV_ID'])
            log(line)

            if outcome == IMPORTED:
                move(imap, uid, settings['IMPORTED_FOLDER'])
            elif outcome == REJECTED:
                move(imap, uid, settings['REJECTED_FOLDER'])
    finally:
        try:
            imap.logout()
        except (imaplib.IMAP4.error, OSError):
            pass


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--env', default=DEFAULT_ENV_FILE, help='env file (default %(default)s)')
    args = parser.parse_args(argv)
    run(load_settings(args.env))
    return 0


if __name__ == '__main__':
    sys.exit(main())
