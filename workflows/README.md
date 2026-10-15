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
