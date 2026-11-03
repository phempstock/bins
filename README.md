# BINS – Bin day reminders for The Park

Email reminders telling residents of The Park, Nottingham, which bins to put out and when. Run by the Nottingham Park Residents' Association (NPRA) at [bins.parknews.co.uk](https://bins.parknews.co.uk). A sister site of [NIMBY](https://nimby.parknews.co.uk), with the same look and the same hosting.

## Why

Nottingham City Council emails bin reminders, but they arrive on the wrong days. BINS sends its own, at a time each subscriber chooses:

- **The evening before** (18:00), or
- **Early on the day** (06:30). Bins must be out by 7am.

## Plan

1. **Data check:** confirm where reliable collection dates come from and whether a street name is enough to know a household's collection day (see below).
2. **Collection data:** a daily job stores the coming weeks' collections for each round in The Park.
3. **Sign-up:** choose your street and reminder time; double opt-in by email; unsubscribe link in every email (as NIMBY).
4. **Reminders:** an hourly cron job sends each subscriber their reminder at the chosen time, never twice.
5. **Launch.** Later: WhatsApp (not started).

## Collection data (checked 3 November 2026)

Two council sources give the same dates:

| Source | What it gives |
| --- | --- |
| `geoserver.nottinghamcity.gov.uk/bincollections2/api/collection/<UPRN>` | The council's own feed, per property: collection day, recycling week (A/B), bins the property has, next collection of each type |
| ReCollect (`api.eu.recollect.net`, area `NottinghamCityCoun`, service 50003) | Powers the council's [bin reminder page](https://www.nottinghamcity.gov.uk/binreminders): a dated calendar per address, including garden waste and bank-holiday changes. Each address belongs to "zones" such as `calendar-thursday-residual-a`, `calendar-thursday-recycling-b`, `calendar-friday-garden-a` |

General waste and recycling alternate weekly on the same weekday. Garden waste is a paid subscription, so not every household has it.

## Project layout

```
public/      web root (bins.parknews.co.uk points here)
deploy/      ionos.sh (server tasks over SSH) and the pinned IONOS host key
.github/     ci.yml (checks, then deploy on push to main), server.yml (run tasks by hand)
```

## Setting up on IONOS

BINS uses the same server, deploy key and host key as NIMBY.

1. **GitHub secrets** (Settings > Secrets and variables > Actions > New repository secret). Repository secrets are per repository, so add the same three values as NIMBY's:
   - `IONOS_SSH_HOST`
   - `IONOS_SSH_USER`
   - `IONOS_SSH_KEY` (the whole private deploy key, including the BEGIN and END lines)
2. **Subdomain:** in the IONOS control panel, point `bins.parknews.co.uk` at the webspace folder `/bins/public` and switch on its SSL certificate.
3. **Database:** create a MariaDB/MySQL database for BINS (details go in `config.php` on the server, never in git).
4. **Mailbox:** `bins@parknews.co.uk` (details go in `config.mail.php` on the server).
5. **First deploy:** Actions > Server tasks > Run workflow > `check`, then `deploy`. After that, every push to `main` deploys automatically once the checks pass.

The repository is public, so its Actions logs are too. The deploy script filters host names, user names, database names, server paths and email addresses out of everything it prints.

## Data licence

Contains public sector information from Nottingham City Council, licensed under the Open Government Licence.
