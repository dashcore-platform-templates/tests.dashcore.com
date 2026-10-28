# Workflow files

Workflows kept as files, reviewed in pull requests, and imported into any
install of tests — this machine or production — the same way:

- **On the page:** Workflows › Import, choose the file.
- **From the command line:** `php artisan tests:import-workflows workflows/customer-journey/01-lets-talk.json`

Either way a file is imported all or nothing, and every workflow in it arrives
**switched off**: press Run now once, check it passes, then switch it on. A
name already taken gets " (2)", so importing a file twice makes a copy rather
than replacing the first.

The format is what Workflows › Export writes (`"format": "dashcore-workflows"`,
`"version": 1`). To change a workflow here, change it in the app, export it,
and commit the file over the old one — or edit the JSON by hand; the test
`WorkflowFilesTest` checks every file here imports cleanly.

## customer-journey/

The customer's whole path, one stage per file, numbered in the order a
customer meets them. These run against **live sites**, so each one stops
short of anything that would reach a real person or cost money unless the
site can recognise a workflow and keep it out of real mail and records.

| # | File | What it proves | Stops before |
|---|------|----------------|--------------|
| 1 | `01-lets-talk.json` | dashcore.com/lets-talk loads and every field takes input | Turnstile and send: live Turnstile gives a headless browser no token, and a real send emails staff and the address typed, makes a sales lead and starts a paid research run |

Still to come, in order: the lead reaching sales, payment, provisioning, first
sign-in, first briefing, upgrade, cancellation. The stand-in versions of
those stages already exist as customer journeys (`tests:journey --list`),
walked against the practice fleet rather than live sites.

## starter/

Four workflows to start an install with: two against **live dashcore.com**,
two against the **local sales stand-in** (`sales.fakecompany.com.test`, the
Proving Ground's fake company — never a real app). Import them all with

    php artisan tests:import-workflows workflows/starter/*.json

They arrive switched off like every import: press Run now on each, check it
passes, then switch it on. `php artisan tests:demo` imports them too (if no
workflow by that name exists yet) and gives each a made-up passed and failed
run; `tests:demo --wipe` removes those runs and keeps the workflows, off.

| # | File | What it proves | Stops before |
|---|------|----------------|--------------|
| 1 | `01-website-lets-talk.json` | dashcore.com/lets-talk loads and every field takes input | Turnstile and send, as `customer-journey/01` does |
| 2 | `02-website-pages.json` | the home, privacy and terms pages load and show their headings | nothing to stop: it only reads |
| 3 | `03-sales-sign-in.json` | a rep (Maya, password `password` from the stand-in's seeders) signs in and lands on the dashboard | — |
| 4 | `04-sales-leads.json` | after signing in, the lead board and the unowned leads list open | changing any lead |

Before switching them on, know that:

- **The sales ones need the stand-in served on this machine.** On 2026-10-28
  `sales.fakecompany.com.test` answered 404 here — there was no
  `~/Herd/sales.fakecompany.com` checkout. Clone it, `tests:prepare-target
  sales`, and they have something to sign in to.
- **The lets-talk one needs the full site.** On 2026-10-28 live dashcore.com
  served a "Something new is coming" page at `/` and `/lets-talk`, without the
  `#df-*` fields, so this workflow fails until the full site is back — which
  is exactly what it is there to say.
