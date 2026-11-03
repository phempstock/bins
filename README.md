# BINS – Bin day reminders for The Park

Which bins to put out, and when, for every street in The Park, Nottingham, with an email reminder the evening before or early on the day. Run by the Nottingham Park Residents' Association (NPRA) at [bins.parknews.co.uk](https://bins.parknews.co.uk). A sister site of [NIMBY](https://nimby.parknews.co.uk), with the same look and the same hosting.

## Why

Nottingham City Council emails bin reminders, but they arrive on the wrong days. BINS sends its own, at the time each subscriber chooses:

- **The evening before**, at 6pm (sent between 18:00 and 22:00 UK time), or
- **Early on the day**, at 6:30am (sent between 06:30 and 08:00). Bins must be out by 7am.

## Where the dates come from

The council's "Check my bin collection day" page ([nottinghamcity.gov.uk/binreminders](https://www.nottinghamcity.gov.uk/binreminders)) is run by ReCollect. Its public calendar gives each address's collection days, with bank holiday changes already applied (for example, Christmas Eve collections moved to 23 December).

```
https://api.eu.recollect.net/api/places/{place_id}/services/50003/events?nomerge=1&hide=reminder_only&after=YYYY-MM-DD&before=YYYY-MM-DD
```

Each event is a day with flags: `Residual` (general waste, green bin), `Recycling` (grey-lidded bin), `Garden` (paid subscription). The council also has its own per-property feed (`geoserver.nottinghamcity.gov.uk/bincollections2/api/collection/<UPRN>`) with the same days.

### Survey of The Park (3 November 2026)

Every numbered address the council's system lists on The Park's streets, about 1,200, was looked up. **No street is split across collection days**, so residents only need to choose their street.

| Round | Streets |
| --- | --- |
| Wednesday: general waste and recycling in alternate weeks | 62 streets, courts and mews: almost all of The Park |
| Thursday: general waste and recycling in alternate weeks | Harlaxton Drive, Clinton Terrace, Derby Road (Park side) |
| Monday: general waste only, weekly | Maria Court (off Fishpond Drive) |
| Friday: garden waste (subscribers only) | Everyone |

The only exceptions are individual flats with shared bins, or no council collection, on the same streets. Streets with only named houses (Tunnel Road, Rocky Mount, Sun Drive and some mews) had no numbered addresses to check; residents there are asked to email.

The street list, with one or two sample addresses per street, is in `src/Park/Streets.php`.

## How it works

- **`bin/sync.php`** (05:23 and 16:23 server time) reads the next eight weeks for every street from its sample address, stores them, and writes `public/data/collections.json` for the website. A street that can't be read keeps what's stored, and the admin is emailed.
- **`bin/send-reminders.php`** (every 15 minutes) sends the evening or morning reminders for that window. Every send is recorded, so nobody is reminded twice; failures are retried up to 3 times. A reminder points out a changed day ("collected on Wednesday this week, not the usual Thursday") and the next collection.
- **Website** (`public/`): pick your street to see the next six bin days (works without signing up), and sign up: email, street, evening or morning, and garden waste if you pay for it. Double opt-in. Every reminder links to a page to change street or time, and to unsubscribe (with `List-Unsubscribe` headers for email programs' own button).
- **Privacy** (`public/privacy.php`): only the street is stored, not the house number. Unconfirmed sign-ups are deleted after 7 days, unsubscribed addresses after 30, the record of sent reminders after 60. IP addresses are kept only as keyed hashes, for a day, to rate-limit the form.

## Project layout

```
bin/         sync.php, send-reminders.php, migrate.php, signup.php (open/close), healthcheck.php
src/         Park/Streets.php (street list), Council/RecollectClient.php, Collections/, Subscribers/, Reminders/, Mail/
migrations/  database schema (MySQL for IONOS, SQLite for tests)
public/      web root (bins.parknews.co.uk points here); data/ is written by the sync
deploy/      ionos.sh (server tasks over SSH) and the pinned IONOS host key
tests/       php tests/run.php; tests/browser/ walk-through
config.example.php   copy to config.php on the server (never committed)
```

## Setting up on IONOS

BINS uses the same server, deploy key and host key as NIMBY, and its cron jobs sit alongside NIMBY's.

1. **GitHub secrets** (Settings > Secrets and variables > Actions): `IONOS_SSH_HOST`, `IONOS_SSH_USER`, `IONOS_SSH_KEY`, the same values as NIMBY's.
2. **Subdomain:** `bins.parknews.co.uk` points at the webspace folder `/bins/public`.
3. **config.php:** copy `config.example.php`, fill in the database and mail settings, and upload it into the `bins` folder (not `public/`). Mail settings can go in `config.php` or in a separate `config.mail.php`:
   ```php
   'mail' => [
       'host' => 'smtp.ionos.co.uk', 'port' => 587, 'encryption' => 'tls',
       'username' => 'bins@parknews.co.uk', 'password' => '...',
       'from_email' => 'bins@parknews.co.uk', 'from_name' => 'BINS – Bin day reminders',
       'reply_to' => 'bins@parknews.co.uk',
   ],
   ```
4. **Actions > Server tasks > `setup`:** creates the tables, runs the first sync and installs the cron jobs.

### Server tasks (Actions > Server tasks > Run workflow)

| Task | What it does |
| --- | --- |
| `check` | PHP, rsync, cron, config, and the health report |
| `deploy` | Copies the code to the server and updates the database tables |
| `setup` | First-time setup: tables, first sync, cron jobs |
| `sync` | Reads the council calendars now |
| `cron` | Reinstalls the cron jobs (sync 05:23 and 16:23 server time; reminders every 15 minutes) |
| `test-reminder` | Sends a sample reminder (Pelham Crescent's next collection) to bins@parknews.co.uk only, marked [TEST] |
| `signup-open`, `signup-close`, `signup-status` | Shows or hides the sign-up form; reports subscriber numbers (never addresses) |

Every push to `main` deploys automatically once all the tests pass. The server's `config.php`, `config.mail.php`, `data/` and `public/data/*.json` are never overwritten. The repository is public, so its Actions logs are too: the deploy script filters host names, user names, database names, server paths and email addresses out of everything it prints.

### By hand over SSH

```
cd ~/bins
/usr/bin/php8.5-cli bin/healthcheck.php
/usr/bin/php8.5-cli bin/send-reminders.php --dry-run --at="2026-11-03 18:05"   # what would be sent
tail data/cache/sync.log data/cache/reminders.log
```

## Development

```
php tests/run.php                              # SQLite, fake ReCollect, no network
bash tests/browser/setup.sh /tmp/bins-site     # throwaway site with made-up calendars
php -S 127.0.0.1:8140 -t /tmp/bins-site/public &
node tests/browser/walkthrough.test.js http://127.0.0.1:8140 /tmp/bins-site
```

GitHub Actions runs these on every push (PHP 8.3 and 8.5, MariaDB 11, Chromium), and only deploys if they pass.

## Roadmap

- [x] Data check: source of dates, street survey
- [x] Collection sync, sign-up, reminders, manage and unsubscribe
- [ ] Live on IONOS, test reminders checked, sign-up opened
- [ ] Later: WhatsApp

## Data licence

Contains public sector information from Nottingham City Council, licensed under the Open Government Licence.
