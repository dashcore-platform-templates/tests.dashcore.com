# dashcore/design-kit

> **This is a mirror. Do not edit it here.** The source is `packages/design-kit` in
> [design.dashcore.com](https://github.com/dashcoretech/design.dashcore.com). Change it
> there and this repo follows on the next push.
>
> An edit made here is not overwritten and is not lost. It stops every release
> instead: the publish is an ordinary push, so a commit this repo has and the
> source does not makes the next one a non-fast-forward, and it is refused.
> Getting publishing working again takes two steps, both under "If somebody
> edits the mirror anyway" below.

Applies a design exported from design.dashcore.com to a Laravel Livewire Flux kit, and ships the five custom components the Dashcore Standard defines.

## Why two places

The package lives in the design repo because it changes with the standard: a rule and the code that applies it move together, in one commit, tested against the real design files. But Composer cannot install a package from a subdirectory, so kits need it as a repo of its own. That repo is this one, published from the other by subtree push, with history.

One is written, the other is published. Never the reverse. It is the same arrangement Symfony and Laravel use, where every component you install is an automatic read-only split of one repository.

This repo is a build artefact. `git subtree split` rewrites the package's folder history into a standalone one, and it is deterministic: the same source always produces the same commits. That is why a normal push is always a fast-forward here, and why a rejection means somebody edited this repo rather than something going wrong with the machinery.

## If somebody edits the mirror anyway

Recovery is two steps, and copying the work across is only the first of them.

```bash
# 1. in design.dashcore.com, put the change where it belongs
#    (copy the file, commit, push)

# 2. then reset this repo onto the split, from design.dashcore.com
git push --force https://github.com/dashcoretech/design-kit.git \
  "$(git subtree split --prefix=packages/design-kit):refs/heads/master"
```

Step 1 alone does not unblock publishing. A split can only ever contain commits the source has, so a commit made here is never in one, and it stays ahead of every future split forever. Step 2 alone would destroy the work. Together they keep it and clear the block.

Step 2 needs write access to this repo, so after the lock below it is an owner's job.

## Locking the mirror

That refusal reports an edit. It does not prevent one. Anyone holding write access here can still commit, and publishing stays broken until somebody moves the commit across. The way to prevent it is access, not rules: GitHub's branch protection and rulesets are not available on a private repository under the Free plan, so the lock has to come from who holds write.

The order matters. The publishing credential is a classic token, and a classic token acts as the person who created it, so taking write access away from people takes it away from the token too. The key has to be in place first.

1. Here, under Settings then Deploy keys, add a key with write access. A deploy key reaches one repository and nothing else, which is the whole point.
2. In design.dashcore.com, add the private half as a secret named `DASHCORE_DESIGN_KEY`. The publish workflow prefers it over `DASHCORE_DESIGN` whenever it is set.
3. Push to main in the design repo and read the publish job. It prints which repository the key belongs to before it pushes, so a key added to the wrong repo says so.
4. Here, under Settings then Collaborators and teams, set every human grant to read. Composer only ever reads, so nothing about installing the package changes.
5. Delete the `DASHCORE_DESIGN` secret and revoke the token behind it.

An organisation owner keeps write and cannot be locked out of their own repo. That is the one hand left that can commit here, and it is enough: the aim is that nobody edits this repo by accident, not that nobody can.

## Install

This repo is republished automatically. A push to main in the design repo updates it, and a `design-kit/vX.Y.Z` tag there releases that version here:

```bash
git tag design-kit/v1.1.0 && git push origin design-kit/v1.1.0
```

If the workflow is down, the same thing by hand, which needs write access to the mirror:

```bash
git subtree push --prefix=packages/design-kit https://github.com/dashcoretech/design-kit.git master
```

In a kit:

```bash
composer config repositories.design-kit vcs https://github.com/dashcoretech/design-kit.git
composer require dashcore/design-kit
```

The fleet is on free Flux. Do not install `livewire/flux-pro`: where section 06 mentions a Pro component, it names the free one to use instead, and `design:apply` warns if it finds Pro installed.

The mirror is private, so Composer needs its own GitHub credentials once per machine. The GitHub CLI's git credential helper is not enough: Composer runs git non-interactively and will fail with "Repository not found". Add a token once, and every private Dashcore package works afterwards:

```bash
composer config --global github-oauth.github.com <a GitHub token with repo scope>
```

## Upgrading to 5.0

One new rule, and it finds nothing on any app here.

**A screen may not restyle the Flux table.** 06's table styling has been in the applied CSS since 14 September: head on `sunken` at 12px in `text-muted`, body at 14px on the density row height, first column `text-strong`, hover on `sunken`. A table is a plain `flux:table` and all of it arrives with the package.

The audit now reports `[data-flux-table]`, `[data-flux-column]`, `[data-flux-cell]` and `[data-flux-row]` in a view.

This is a major because a build that passes on 4.0 can fail on 5.0, not because anything failed here. Measured on three apps before shipping it: nothing. The one screen that had it was fixed first, and it is worth knowing how it read, because the next one will read the same way. The screen used `text-muted-on-sunken` for the head where the adapter uses `text-muted`, so the utilities won on the head while the adapter supplied the hover and the first column. The table matched neither the mockup nor the standard, and no rule could see it.

Other Flux internals are left alone. This is 06's argument about the table, not a ban on touching a data attribute.

**Also in this release.** `design:update` and the snippet the tool hands out now say `composer require "dashcore/design-kit:<major>"` rather than `composer update dashcore/design-kit`. Plain `composer update` stays inside the major an app is pinned to, so the command that was meant to move an app onto a new major could never have done it.

## Upgrading to 4.0

One thing breaks, and it lands on one product rather than the fleet.

**A Flux button at `size="sm"` is a tap target finding.** 05 has always said a touch target is never under 36px. Flux's heights are `h-10` at 40px for `base`, `h-8` at 32px for `sm` and `h-6` at 24px for `xs`, so `base` is the only size that clears the floor.

The rule caught `xs` and not `sm`, while already reporting a hand-written `h-8` button, so it was inconsistent with itself.

Measured on five apps: **63 in one and 68 in another, which are the same product, and nothing at all in the other three.** So this is one app's bill, not the fleet's, and it is a real one.

An inline action in a dense row is a full-height button, or it is not a button. Where a control genuinely is not a tap target, say so on the line with a reason:

```blade
<flux:button size="sm">Save</flux:button> {{-- @ds-audit-ignore a control in this tool's own chrome, on a two-pane desktop screen nobody opens on a phone --}}
```

That is not a loophole to reach for. design.dashcore.com itself took eight of them and said why on each, which is worth knowing when you decide whether your own are the same case.

**Nothing else here breaks.** The rest is new:

- **A design file names its own slug.** `design.json` carries `slug`, so `php artisan design:update` takes no argument on an app already on the standard. A file written before this cannot have one inferred, and the command says so rather than guessing; pass the slug once and it is written in.
- **`php artisan design:audit --report`** sends counts by rule to design.dashcore.com, for the fleet view. It needs `DESIGN_TOKEN`, which is that app's token, issued on the Fleet page and set in CI. Counts by rule and two version stamps only, never findings, never file paths, never any of the repo's source. It never fails the audit: a dashboard that could not be updated is not a reason for a build to go red.

## Upgrading to 3.0

One thing breaks: the audit gains a rule that fires on every app in the fleet.

**Headings go through `x-ds::section-heading`.** `flux:heading` cannot make either heading role in section 03. `size="lg"` renders 16px at weight 500 where a section heading is 18px at 600, and `size="xl"` renders 24px at 500 where a page title is 24px at 600. The weight is wrong at every size. Its sizes are plain utility classes on the element, so adding a class of your own leaves two competing and the stylesheet order decides rather than you.

So `design:audit` now reports `flux:heading size="lg"`, and a build that passed on 2.0 can fail on 3.0. Measured on five apps here, it finds 1, 1, 1, 44 and 53. One of those five had zero findings an hour before this was written.

```blade
<x-ds::section-heading>Recent activity</x-ds::section-heading>
<x-ds::section-heading level="3">Delete this item?</x-ds::section-heading>
```

It is 18px at 600 in the body face, and it covers a section heading, a card title and a modal title. The body face and never the second one: 03 gives the second face the page title, the sign-in headline and stat values, and bars a serif from anything under 24px. A page title is still `x-ds::page-header`.

`flux:heading` at its default size is 14px at 500, which is the UI emphasis role in 03, and that one is correct. It is left alone.

**Nothing else here breaks.** The rest is new:

- `php artisan design:check --against=<slug>` says whether the standard moved since this app applied, without changing anything. See "Update an app that is already on the standard".
- Standard section 13 governs public pages, which used to be exempted. A landing page still carrying `@ds-audit-ignore-file` keeps working exactly as before; swapping it for `@ds-public` is what opts it into the rules.
- The audit reports `--ds-prose` used as a width or a height. It is the prose type size from 05, so as a width it gives a column 14 pixels across. It found nothing on any app here; it was written after it caught one of ours.
- Section 06 said `callout.button`, which has never existed in either Flux tier. It says `callout.link` now.

## Upgrading to 2.0

Three things change in a way that can break an app that works on 1.0. None of them is a bug; they are fixes arriving.

**Re-apply by slug, not by file.** `design:apply` measures contrast before it writes, and 2.0 measures two pairs 1.0 never did: text on the accent tint that sits behind every selected row and active list item. A `design/design.json` produced by 1.0 fails them, at about 3.40:1 in dark against a floor of 4.5. Checked against three real apps, all three fail. So this refuses:

```bash
php artisan design:apply design/design.json
```

and this is the upgrade, because it re-pulls the design and gets corrected values:

```bash
php artisan design:update <slug>
```

**A badge with no status is now neutral rather than blue.** Any screen using a bare `<x-ds::badge>` changes appearance. That is the rule in 06: colour on a badge means status, and a category is not a status.

**The audit has a new rule.** It reports an interactive element sized under 36px, which 05 has always required and nothing enforced. A build that runs `design:audit` and passed on 1.0 can fail on 2.0. It found nothing on the apps tested here.

The Flux table also picks up the styling 06 always specified, and the empty state gains a compact size. Neither breaks anything.

## Versions

`composer update dashcore/design-kit`. That is the whole thing, and it stays true at 6 and at 10, because nothing here names a version.

A new audit rule is a minor. It used to be a major, on the grounds that a rule could fail a build that passed, and that was true for one reason: `design:audit --report` exited non-zero when it found something, so the one step every app is told to add turned red the moment a rule landed. Reporting is telling the board what is true, which succeeds whatever it found, and it does that now. A new rule shows up as findings on the fleet and nothing goes red, so there is nothing left for a major to protect and rules arrive on an ordinary update.

That also ends the thing that made it worse: an app could not take a new command without taking every rule that shipped alongside it. ExecutiveOS sat on 4.0 unable to get `design:connect` without accepting sixty-eight findings of unrelated work.

An app that does want findings to fail its build runs `php artisan design:audit` without `--report`, as its own check. That is opt-in, and an app that opts in has accepted that a new rule can stop it.

A major is now what it is everywhere else: the package's own interface breaking. Rare, and nothing to do with the standard moving.

Before 1.0 this was a 0.x package, where Composer reads each minor as breaking. A kit that had required `^0.2.0` never saw v0.3.0 at all, and said so as "Nothing to modify in lock file" rather than as an error. If you have one of those, change the constraint to `^1.0` once and it will keep up from then on.

Working on the design repo and a kit side by side, a path repository skips the mirror:

```bash
composer config repositories.design-kit path ../design.dashcore.com/packages/design-kit
composer require dashcore/design-kit:@dev
```

Composer links rather than copies, including on Windows, where it makes a junction. Edits to the package are live in the kit with nothing to re-run. Checked on Windows 11 with Composer 2: `vendor/dashcore/design-kit` came back as `LinkType: Junction` pointing at the package, and an edit made a minute earlier was already there.

Do not `rm -rf` that directory. It is a link into the design repo, and some tools delete through a junction rather than removing it, which takes the package source with it. `composer remove dashcore/design-kit` if you want it gone.

A kit set up this way is a development kit, not a fleet app. It has no version, so `composer update` has nothing to do and `composer require "dashcore/design-kit:5.*"` cannot resolve: a directory carries no tags, which is why the installed version reads `dev-master`. It also runs whatever is in the design repo's working tree, including changes nobody has released, so what it reports to the fleet board is evidence about that tree rather than about the fleet. Useful as a canary, and worth remembering before reading its row as a fact about the apps.

## Connect to the fleet board

```bash
php artisan design:connect
```

Says whether this repository can reach the board, and what is missing if it cannot. It registers nothing: a repository appears on the board the first time it reports.

Reporting needs one block on the job that runs the audit:

```yaml
permissions:
  id-token: write
  contents: read
```

GitHub then hands the job a short signed document naming the repository, and `design:audit --report` sends that. Nothing is stored in the repository and there is nothing to rotate. Naming any permission turns GitHub's defaults off, so `contents: read` is not padding: without it the job cannot check itself out.

`DESIGN_TOKEN` is still accepted for reporting, for apps that have not added the permission yet.

Pulling a design is the other half and needs a token of yours. `design:apply`, `design:update` and `design:check --against` fetch a pack, a pack carries the brief, and the brief names the client. Make one at `/account`, name it after the machine, and put it in that app's `.env`:

```
DESIGN_TOKEN=dsn_…
```

One token covers every app you work on, because it is yours rather than any repository's. It never goes in CI: a workflow reports and never pulls.

## Take the Dashcore style

Every Dashcore app takes one style, `dashcore` (standard section 14), instead of a design of its own. It is read from this package on every build rather than copied into the app, so a release restyles the whole fleet.

```bash
composer require dashcore/design-kit
php artisan design:apply --style=dashcore
npm run build
```

That writes `design/design.json` naming the style, puts two lines after Tailwind in `resources/css/app.css`, and adds the frame's script to `resources/js/app.js`. The standard itself ships in the package as `STANDARD.md`, so there is no pack to unzip: point the app's `CLAUDE.md` at `vendor/dashcore/design-kit/STANDARD.md`.

```css
@import 'tailwindcss';
@import '../../vendor/dashcore/design-kit/dist/dashcore.css';
@source '../../vendor/dashcore/design-kit/resources/views';
```

An app without Flux draws its frame with the package's components:

```blade
<head>
    <x-ds::theme-script />
    <x-ds::fonts />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <x-ds::shell :menu="$menu" product="Finance" :home="route('dashboard')" navigate>
        <x-ds::page-header title="Runway" description="Twelve months at the current burn." />
        <x-ds::flash />
        {{ $slot }}

        <x-slot:footer>
            <x-ds::account :name="$user->name" :email="$user->email">
                <x-ds::theme-toggle />
            </x-ds::account>
        </x-slot:footer>
    </x-ds::shell>
</body>
```

The menu takes the shapes `dashcore/ui` took (`Dashcore\DesignKit\Frame\Menu` lists them). An app's own test holds it to the shell:

```php
\Dashcore\DesignKit\Testing\Shell::assertConforms($this->get('/')->getContent());
```

### Moving off dashcore/ui

For one major, an app moving across changes no view. `<x-dashcore::…>` still renders, the `dc-*` colour names are aliases of the roles, and `Dashcore\Ui\Menu`, `Version` and `Testing\Shell` resolve to this package's. Swap the dependency and apply the style:

```bash
composer remove dashcore/ui
composer require dashcore/design-kit
php artisan design:apply --style=dashcore
```

Where `dashcore/ui` and the standard differed, the standard won: 12px labels in mixed case rather than 11px uppercase, the sidebar at 16rem on `sunken`, the status colours in 04, a 24px title at weight 600, a destructive button as an outline. Screens look slightly different afterwards; nothing breaks.

With Flux, the notes `dashcore/ui` carried still hold: do not wrap pages in `<flux:main>` inside the shell, and use `<x-ds::account>` in the footer rather than `<flux:sidebar.profile>`.

## Hold an app to the standard where it is

An app that is far off the standard can still be held to it, from the day it joins:

```bash
php artisan design:audit --baseline   # records today's counts in design/allowance.json; commit it
php artisan design:audit              # in CI: fails only when a rule's count rises
```

The allowance only shrinks: run `--baseline` again after fixing screens and it keeps the lower count for every rule. A rule a newer release of this package adds is allowed at whatever it finds until it is recorded, so an update never turns a build red. Standard section 09 has the detail.

## Apply a design

Give it the design's slug, its pack URL from design.dashcore.com, or a local design file:

```bash
php artisan design:apply https://design.dashcore.com/designs/meridian-credit-union/pack.json
php artisan design:apply meridian-credit-union      # with DESIGN_SOURCE in .env pointing at your design.dashcore.com
php artisan design:apply design/design.json         # a pack unzipped into the repo root
npm run build
```

A slug or a URL pulls the pack's four files into `design/` first, so the repo ends up with exactly what a downloaded zip would have given it, then applies.

What it changes: the four pack files under `design/` when pulled, a marked block at the end of `resources/css/app.css` (Flux theme variables, the `--ds-*` roles, the dark muted-text correction), the fonts array in `vite.config.js`, the brand and design keys in `.env` and `.env.example`, `resources/views/layouts/auth.blade.php` (now the two-panel sign-in), the logo copied to `public/images/` if the brief had one, the four Lucide-named icon overrides deleted, the kit's three stock sign-in layouts under `layouts/auth/` deleted once nothing references them, and the starter kit's own off-standard lines rewritten onto the roles and the scale. It asks before each delete; `--force` skips the questions. Running it twice changes nothing. `--check` prints the plan and exits non-zero if anything would change.

`php artisan design:check design/design.json` measures contrast on the file.

Add `--against=<slug>` and it also says whether the standard moved since this app applied, without changing anything:

```bash
php artisan design:check --against=harbor-point
```

It compares the stamp in `design/STANDARD.md` with the version the tool is publishing now. Until this, the only way to find out was to re-apply, which is a change made to answer a question. It never alters the exit code, because half the entries in the changelog are relaxations with nothing to do.

## Update an app that is already on the standard

Setting an app up happens once. When the standard changes afterwards, do not repeat it:

```bash
composer require "dashcore/design-kit:5.*"   # the components and the audit rules live here, so this is first
php artisan design:update <slug>
```

`design:update` re-applies the design, says whether the standard moved, and runs the audit against the rules as they now read. It does not run Composer for you, because a command should not update the package it is currently executing from, and it refuses to run on an app that has no `design/design.json`.

`design:apply` carries the same check on its own. The top of `design/STANDARD.md` holds a stamp, which is the standard's text hashed, and a mismatch is reported by name. `design/CHANGELOG.md` says what moved, and whether each change is a relaxation with nothing to do or a tightening the audit can find for you.

## Audit and migrate

```bash
php artisan design:audit                                             # every view, the map
php artisan design:audit resources/views/pages/dashboard.blade.php   # one screen, the check
php artisan design:audit --fix                                       # weights and off-scale spacing, nothing needing judgment
```

It lists what is off the standard per file and by rule, and exits non-zero while anything is. It reports what a text scan can see; whether a screen is composed well is for a person with the screen open. The pack's `design/MIGRATION.md` is the procedure an agent follows to bring an existing app over, one screen per commit, using the audit on each file as its check.

A line that is deliberately off says so, and the audit leaves it alone. A whole file that sits outside the app standard, a landing page or a legal document, says so once at the top and is listed as skipped:

```blade
<div class="p-5"> {{-- @ds-audit-ignore the brand mark is fixed at this size --}}

{{-- @ds-audit-ignore-file public landing page, composed outside the app standard --}}
```

An ignore without a reason is worse than the finding.

## Components

```blade
<x-ds::page-header title="Today" description="Tuesday 15 September.">
    <flux:button variant="primary">New item</flux:button>
</x-ds::page-header>

<x-ds::stat-row>
    <x-ds::stat label="Open items" value="148" delta="+12 since yesterday" tone="up" first />
    <x-ds::stat label="Needs attention" value="9" delta="3 waiting on you" tone="warn" />
</x-ds::stat-row>

<x-ds::empty icon="inbox" title="Nothing here yet." description="Add one from the button above." />
```

They read the motif and title face from `config('brand')`, which `design:apply` writes, so they match the mockups in the tool.

## Publish to edit

```bash
php artisan vendor:publish --tag=design-kit-views
php artisan vendor:publish --tag=design-kit-config
```
