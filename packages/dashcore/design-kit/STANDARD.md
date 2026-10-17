# The Dashcore Standard

Standard version: 53a6a515

That stamp is the text below, hashed. If it differs from the one in your `design/STANDARD.md`, the standard moved since you last applied a design, and `design/CHANGELOG.md` says what changed.

Exported from design.dashcore.com. Every section of the standard in full from 01, including the rules the generator does not read: component behaviour, shell geometry, motion, the mapping onto Flux, and the sections for email, error pages, widths and public pages. Read alongside design.json before building or changing any screen.

The text below says "the fleet" throughout. The fleet is every app Dashcore runs, about fifteen of them, which have to look and work the same without looking like one client's app repainted. That number is the test a rule has to pass, and it is why a rule here is not open to a project: what suits one client is a preference, and the seven decisions in design.json are where a project differs.

---

# 01. Principles

Six things every screen in the fleet is true to. Each one is checkable.

1. **The content decides the layout.** A screen is composed around the tasks and data it actually holds. Three equal boxes in a row, a hero with a badge above it, or a grid of icon tiles are not layouts, they are the absence of one. Check: can each region be named by what it does for the user.

2. **One accent, spent carefully.** Every project has one accent colour. It marks the primary action, the active nav item, links, focus, and selection, and it appears at most once per row of content. Backgrounds, headings and body text never take it. Check: count the accent uses in a viewport; if any row has two, one is wrong.

3. **Structure is shared, identity is not.** Two Dashcore apps share the same shell regions, scales, components, icons and motion. They differ in accent, neutral temperature, type pairing, radius, density, one motif, and their sign-in words. Check: side by side, two apps should be recognisably the same product family and not mistaken for each other.

4. **Contrast is measured, not eyeballed.** Body and muted text sit at 4.5:1 or better on the surface they are actually on, in both registers. The figures are in 04. Check: run the contrast script on the resolved tokens; it fails the build if any role fails.

5. **Spacing comes from one scale.** Every gap, padding and gutter is a value from the eight-step scale in 05 and is decided by density, not by the person laying out the page. Check: no spacing value on a screen is outside the scale.

6. **Copy is concrete.** Headings say what a thing is. Empty states say what to do. Sign-in copy is about the client, not about software. No word from the buzzword list in the design principles. Check: read every string aloud to someone outside the project.


---

# 02. Shells

Two shells. A project uses one, chosen by a person in the brief, never by the generator. Sidebar is for operations tools used for hours at a time with more than six destinations. Top nav is for lighter apps: a portal opened once a week, a public-facing tool, anything with six or fewer destinations. Both share every region except the navigation.

Every main view opens with the **page header**: an optional eyebrow in the label style, the page title, an optional one-line description, and actions on the right, at most one of them primary. The title goes through the `x-ds::page-header` component rather than a bare heading, because that is what applies the pairing's second face at title size. A title set any other way loses the serif or display face entirely.

Content is composed around what the screen actually holds. Three equal columns are allowed only when there are exactly three peers of equal weight.

**Sign-in** is a two-panel page outside the shell. The brand panel carries the generated copy: wordmark, eyebrow, headline, tagline, footer line. The form panel holds the form. Below `lg` the brand panel sits above the form as a brand block, carrying the wordmark and headline only; 12 says why the rest is dropped.


## Sidebar shell

Regions, in order:

- **Sidebar**, 16rem wide, full height, sticky. Surface colour is `sunken` in light and `surface` in dark. Right border one pixel `border`. Contains, top to bottom: brand block, nav groups, spacer, user menu. Collapses to a drawer below `lg`, opened from a header that exists only below that width. It is never narrowed and never simply hidden.
- **Brand block**. Wordmark or logo on the left, product name in `text-strong`, one optional line of `text-muted` beneath it. Height 3.5rem in comfortable, 3rem in compact. No tagline paragraphs here.
- **Nav groups**. At most two levels: a group heading in the label style from 03, then items. At most eight items visible per group before it scrolls. Each item is an icon plus a label, 14px, `text` colour, `text-strong` when active. Active state is marked by the accent as the chosen motif dictates (07), never by a filled accent background across the whole item.
- **User menu**. Pinned to the bottom. Avatar or initials, name, and a dropdown with settings and sign out. Nothing else lives here.
- **Main**. Everything to the right. Page background `page`. Contains a page header and a content area.

A Flux app draws this with `flux:sidebar`; an app without Flux draws it with `x-ds::shell` (06). The regions are the same either way.

Dark follows the operating system unless the person has chosen. An app that offers the choice records it as a `dark` or `light` class on `<html>`, and an explicit light choice beats a dark operating system.

## Top nav shell

- **Header bar**, 3.5rem tall, full width, sticky, `surface` colour with a one pixel bottom `border`. Left to right: brand block, primary nav items inline, spacer, user menu. The items stay inline down to `md`. Below that they move into a `flux:navmenu` behind a toggle, and the brand block stays.
- **Main**. Content is constrained to a max width of 80rem and centred. Same page header and content area as the sidebar shell.

## The page header in detail

Padding from the spacing scale for the chosen density.

- Optional eyebrow, label style from 03, `text-muted` or accent per the motif.
- Title, `text-strong`, page-title size from 03, in the pairing's display face if it has one. Always through `x-ds::page-header`.
- Optional one-line description in `text-muted`. One sentence. Not a paragraph.
- Actions on the right, vertically aligned to the title baseline. At most one primary button and two secondary ones. Actions wrap below the title below `md`, and the title never truncates to make room for them.
- A one pixel `border` beneath the whole header when the motif is hairline; otherwise the header is separated from content by spacing only.

## The content area

- Padded by the page gutter for the chosen density (05).
- Laid out on a 12 column grid with the section gap from 05. Regions are placed by content: a stat row spans 12, a table spans 12, a form spans 8 with 4 of notes, two related panels split 6 and 6, a detail view is 8 and 4.
- Vertical rhythm is the section gap between regions and the card padding within them. Nothing else.

## Sign-in in detail

Left panel, 40 percent of the width on desktop, carries the brand. Its surface is `sunken` in light and `surface` in dark, with an optional hero image. Right panel, 60 percent, is `page` and holds the form: heading, email, password, remember me, primary button, and the secondary links.

## Rules

- No third shell. If a case needs one, it is a change to the standard, not a project decision.
- The sidebar never holds content, search, or stats. It is navigation and identity.
- Breadcrumbs appear only when the view is three or more levels deep.
- A footer is not part of either shell.


---

# 03. Type

## The scale

Flux sets nearly all interface text at 14px, so the scale is built around that rather than against it. Sizes in pixels; line height as a ratio.

| Role | Size | Line height | Weight | Tailwind |
|---|---|---|---|---|
| Label, eyebrow, table head | 12 | 1.33 | 500 | `text-xs font-medium` |
| UI text, table cells, nav, controls | 14 | 1.5 | 400 | `text-sm` |
| UI emphasis | 14 | 1.5 | 500 | `text-sm font-medium` |
| Prose and form inputs, comfortable density | 16 | 1.5 | 400 | `text-base` |
| Section heading, card title | 18 | 1.33 | 600 | `text-lg font-semibold` |
| Page title | 24 | 1.25 | 600 | `text-2xl font-semibold` |
| Stat value | 30 | 1 | 600 | `text-3xl font-semibold tabular-nums` |
| Sign-in headline | 36 | 1.1 | 600 | `text-4xl font-semibold` |

Three weights only: 400, 500, 600. Bold at 700 is not used; it is louder than any layout here needs. Nothing is set below 12px, ever, including in badges and table heads. Numbers in tables and stat tiles are always tabular and right aligned.

## Roles for the two faces

Every pairing is a body face and a second face. The body face carries all UI text, prose, forms, tables and nav. The second face is one of three kinds and has a fixed job:

- **A mono face** is used for labels and eyebrows when the eyebrow motif is chosen, for IDs, codes and reference numbers in tables, and optionally for stat values. Never for body or headings.
- **A serif face** is used for the page title and the sign-in headline only. Never for body, UI, or anything under 24px.
- **A display sans face** (a narrow, condensed or expressive cut) is used for the page title, stat values and the sign-in headline. Never for body or UI.

No face is set uppercase at title size.

## The approved pairings

The generator picks one, a person may change it, and there is nothing else. Approved means someone has looked at the pairing rendered, at every size, in both registers, on the Components screen. A face nobody has seen is not on this list.

Five is not a limit on how different two projects can be. The seven decisions give 360 structural combinations against fifteen apps in the fleet, before the accent is counted at all.

Marked with what kind of project each suits, so the generator has something to reason from besides mood.

| # | Body | Second face | Kind | Suits |
|---|---|---|---|---|
| 1 | IBM Plex Sans | IBM Plex Mono | mono | Operations, logistics, anything with IDs and codes in every row. Neutral and slightly technical. |
| 2 | Source Sans 3 | Source Serif 4 | serif | Clinics, law, accounting, professional services. Quiet and trustworthy. |
| 3 | Manrope | JetBrains Mono | mono | Modern product tools, dashboards with lots of numbers. Rounder and warmer than 1. |
| 7 | Hanken Grotesk | Newsreader | serif | Consulting, editorial, strategy. Newsreader at title size gives an authored feel. |
| 10 | Atkinson Hyperlegible Next | Spline Sans Mono | mono | Healthcare, older or mixed-ability users, anything where legibility beats character. |

Excluded by name, so the generator never reaches for them: Inter, Instrument Sans, Space Grotesk, Instrument Serif, Barlow Condensed, Bebas Neue, Space Mono, DM Sans. The first four are on the tells list or are the starter default. The last four are the retired OS Plus set.


## Ids, and what was retired

Ids are stored in every design file and exported pack, so they are stable: a retired pairing leaves a gap rather than renumbering the ones after it. All five faces exist on Bunny Fonts.

Retired on 11 September 2026, after the first three projects: Public Sans with Fraunces and Red Hat in two cuts both overlapped the serif and safe-corporate ground that 2 and 7 already hold; Figtree with Geist Mono was a softer 3; Archivo Narrow was the only condensed face and carried an uppercase-title rule that existed for it alone; Albert Sans with Bricolage Grotesque was the one with personality, and the fleet is admin tools. Adding a pairing back is an entry in `App\Design\Pairings` and a row above, once someone has looked at it.

The resolver still carries a condensed flag on the second face, in case such a pairing returns. No pairing sets it today, which is why the uppercase-title rule is gone.

## Labels

Labels are set with letter spacing of 0.04em. When the eyebrow motif is chosen (07) labels are uppercase with 0.08em spacing and set in the pairing's mono face if it has one.

## Loading

Fonts load through the starter kit's Vite font pipeline from Bunny Fonts, with weights 400, 500 and 600 only, and the second face at only the weights its role needs. Every face has a real fallback stack. Font files are not committed to the repo. A kit with no Vite fonts array loads them from a link in the head partial instead; the adapter handles both.

## Rules

- One pairing per project. Never a third face.
- No italic in UI. Italic is allowed only in prose the user wrote.
- Line length in prose is capped at 65 characters (`max-w-prose`).


---

# 04. Colour

Colour is expressed as roles. Components read roles, never raw values. The resolver turns the seven decisions into a hex value for each role per register, and the adapter maps them onto Flux's variables so every Flux component follows.

## Roles

| Role | Job |
|---|---|
| `page` | Outermost background |
| `surface` | Cards, panels, table bodies, header bar |
| `sunken` | Sidebar in light, table heads, grouped inputs |
| `border` | Every divider and outline |
| `border-strong` | Input borders |
| `text-strong` | Titles, table first column, values |
| `text` | Body, UI, nav |
| `text-muted` | Descriptions, labels, secondary cells |
| `accent` | Primary button fill, active indicator, selection |
| `accent-hover` | The above on hover |
| `accent-content` | Accent used as text or icon |
| `accent-soft` | Accent as a background tint |
| `on-accent` | Text on an accent fill |

## The three neutral temperatures

The generator picks one per project. **Pure** is a true grey and suits technical and financial work. **Cool** leans blue and suits software and data. **Warm** leans brown and suits people-facing work, hospitality and health. The scale itself is fixed and the resolver fills it in.

## Accent rules

- One accent. The generator chooses a hue and a saturation; the resolver builds the shades.
- The accent hue must sit at least 30 degrees away from red (0) and amber (40) so it is never read as danger or warning. Green near 140 is allowed but discouraged, since success then loses its meaning.
- Saturation is between 45 and 85 percent in HSL. Under that it reads as a grey; over it, it vibrates on white.
- As a fill, the accent appears on: the primary button, the active nav indicator, the checked state of switches, checkboxes and radios, progress fills, and a count marker on a nav item or a notification bell. Nowhere else. A count marker is small, carries `on-accent` text, and sits at `text-xs` like everything else, because 03 sets no size below 12px. A count is attention and never danger, so it is never red: red says something has gone wrong, and a pile of unread items has not.
- As text or icon, only `accent-content` is used. It is derived against the accent tint rather than the page, because that tint sits behind selected rows and active list items and is the worst of the three surfaces accent text lands on. Passing there means passing on page and surface too.
- Secondary text on that tint takes `text-muted-on-sunken`, the same role a status tint uses. Plain `text-muted` is the 500 step and does not clear a tint.
- Never on page or surface backgrounds. `accent-soft` may tint one region per view when the filled-stat motif is chosen, and is otherwise used only behind selected rows and active list items.
- Headings and body text never take the accent.

## Status colours, fixed fleet-wide

These never change per project.

| Status | Light text | Dark text | Fill |
|---|---|---|---|
| Success | green 700 | green 400 | green 700 |
| Warning | amber 700 | amber 400 | amber 700 |
| Danger | red 700 | red 400 | red 600 |
| Info | blue 700 | blue 400 | blue 700 |

Status colours mark status. A green button is not a primary button; a red-outlined one is a destructive one, and nothing is ever filled red. Success, warning and danger appear only on badges, callouts, toasts, form errors, deltas, and a progress bar measured against a limit (06).

## The contrast floor

- Body text, muted text, labels, and text on filled controls: 4.5:1 or better against the surface they are actually on.
- Icons and borders that are the only signal a control exists: 3:1 or better.
- Decorative borders and dividers, and the resting outline of an input that also has a label and a focus ring: no floor. They are allowed to be quiet.
- Both registers are checked separately. Passing in light says nothing about dark.


## The temperature scales

| Step | Pure | Cool | Warm |
|---|---|---|---|
| 50 | #fafafa | #f8fafc | #fafaf9 |
| 100 | #f5f5f5 | #f1f5f9 | #f5f5f4 |
| 200 | #e5e5e5 | #e2e8f0 | #e7e5e4 |
| 300 | #d4d4d4 | #cbd5e1 | #d6d3d1 |
| 400 | #a3a3a3 | #94a3b8 | #a8a29e |
| 500 | #737373 | #64748b | #78716c |
| 600 | #525252 | #475569 | #57534e |
| 700 | #404040 | #334155 | #44403c |
| 800 | #262626 | #1e293b | #292524 |
| 900 | #171717 | #0f172a | #1c1917 |
| 950 | #0a0a0a | #020617 | #0c0a09 |

These are Tailwind's neutral, slate and stone scales. They are used because they are tuned and because the temperature is the decision, not the individual stops.

## Which step fills which role

| Role | Light | Dark |
|---|---|---|
| `page` | white | 900 |
| `surface` | white | 800 |
| `sunken` | 50 | 950 |
| `border` | 200 | 700 |
| `border-strong` | 300 | 600 |
| `text-strong` | 950 | 50 |
| `text` | 800 | 200 |
| `text-muted` | 500 on page or surface, 600 on sunken or a status tint | 400 |

`accent-hover` moves the accent's lightness six points. `accent-content` moves it until it reaches 4.5:1 on the page. `accent-soft` is the accent at 10 percent alpha in light and 16 in dark. `on-accent` is white or near-black, whichever passes on the fill; if neither does, the fill itself moves until one does. In dark the accent is lightened until it reaches 3:1 on the dark page before any of that.

## Status colours, measured

| Status | Light text | Dark text | Fill | Soft light | Soft dark |
|---|---|---|---|---|---|
| Success | #15803d | #4ade80 | #15803d | green 50 | green 950 |
| Warning | #b45309 | #fbbf24 | #b45309 | amber 50 | amber 950 |
| Danger | #b91c1c | #f87171 | #dc2626 | red 50 | red 950 |
| Info | #1d4ed8 | #60a5fa | #1d4ed8 | blue 50 | blue 950 |

Green 600 fails as text on white at 3.3:1, and amber 600 at 3.19:1, which is why the text shade is 700. In dark, every 700 shade fails on neutral 800, which is why the text shade is 400. Fills at 700 pass with white text at 5.02:1 or better. Red 600 is kept as the danger fill because it passes at 4.83:1 and is the shade people recognise.

Flux's own badge colours come from Tailwind's palette rather than these, so the adapter overrides them. Otherwise a kit's badges drift from the standard the moment anyone uses one.

## What the floor measures out at

| Pair | Light | Dark |
|---|---|---|
| text-strong on page | 19.8 to 20.2 | 16.7 to 17.2 |
| text on page | 14.6 to 15.2 | 13.9 to 14.5 |
| text on surface | same | 11.9 to 12.1 |
| text-muted on page | 4.74 to 4.80 | 6.9 to 7.1 |
| text-muted on surface | same | 5.7 to 6.0 |
| text-muted on sunken | 4.54 to 4.59 using 500; 6.9 to 7.2 using 600 | n/a, sunken is darker than page |
| text-muted on a status tint | 4.35 to 4.59 using 500, which fails on danger and info; 5.7 or better using 600 | 5.7 to 6.4 |
| status text on its own tint | 4.79 to 6.16 | 5.7 to 9.0 |
| accent-content on the accent tint | 4.65 to 6.71 | 4.55 to 4.85 |
| text-muted on the accent tint | 4.06 to 4.31 using 500, which fails; 6.4 or better using 600 | 4.9 to 5.3 |

Three things fall out of the numbers. Muted text at 500 only just passes on white and fails on neutral 100, so muted text on any sunken surface uses 600 in light. Flux's default of neutral 500 for muted text in dark fails at 3.07 to 3.19:1 on neutral 800, so the standard sets dark muted text to 400 and the adapter overrides Flux there.

The accent's own tint is the same story, and it went unnoticed for longer. `accent-content` used to be derived against the page, where it passed, and nothing measured it against the tint the standard itself puts behind every selected row. In dark it sat at 3.40:1 while every design reported passing. It is now derived against the tint, and both pairs are measured.

And a status tint is a quiet surface like any other. Muted text at 500 measures 4.35:1 on the danger tint and 4.37:1 on the info tint in light, so secondary text on a tinted row takes the same 600 step as on sunken. The role carries the name `text-muted-on-sunken` from where it was first needed; read it as muted text on any quiet surface that is not page or surface. A badge is safe as it stands, because status text on its own tint never drops below 4.79:1. Both pairs are measured for every status, in both registers, so neither can go quietly wrong again.

The resting outline of an input measures about 1.5:1 at the 300 step. That is deliberate, decided on 11 September 2026: the two pixel accent focus ring carries the meaning, and the control already announces itself by shape, label and placeholder. The contrast command reports the pair as information rather than a failure.

## Mapping onto Flux

The adapter writes these into `@theme`:

- `--color-zinc-50` through `--color-zinc-950` take the chosen temperature's scale. This is what rethemes every Flux component.
- `--color-accent` takes `accent`, `--color-accent-content` takes `accent-content`, `--color-accent-foreground` takes `on-accent`. Dark values are set on `.dark`.
- Muted text in dark is corrected with `.dark { --color-zinc-500: <neutral 400 value>; }`.
- Flux's badge colours are overridden to the status roles above.

The semantic roles themselves are also emitted as `--ds-page`, `--ds-surface`, `--ds-text-muted` and so on, for the four custom components in 06 and for any non-Flux CSS a project writes.


---

# 05. Spacing, radius, density

## The spacing scale

Eight steps on a 4px base, and nothing in between: 4, 8, 12, 16, 24, 32, 48, 64. If a layout seems to need 20 or 40, the layout is wrong, not the scale.

## Density

Two densities. Comfortable is for apps used occasionally or by the public. Compact is for tools used all day by trained staff, and only then. Density sets spacing, never type: UI text stays 14px in both.

| Measure | Comfortable | Compact |
|---|---|---|
| Page gutter and section gap | 32 | 24 |
| Card, panel and stat tile padding | 24 | 16 |
| Table row height | 44 | 44 |
| Control height | 40 | 36 |
| Gap between form fields | 24 | 16 |
| Prose and input text size | 16 | 14 |

Table rows are 44px in both densities. A row holds one line of 14px text and, at most, a 40px control, so the height is set by what sits in it rather than by the density; compact tightens the cell padding instead.

## Radius

Three steps. **Sharp** suits dense data and technical work. **Soft** is the default when the brief gives no reason. **Round** suits consumer-facing and people-facing apps. Radius never varies within a project: a sharp table beside a round card is the inconsistency the principles call a bug.


## The density table in full

| Measure | Comfortable | Compact |
|---|---|---|
| Page gutter | 32 | 24 |
| Section gap between regions | 32 | 24 |
| Card and panel padding | 24 | 16 |
| Stat tile padding | 24 | 16 |
| Table row height | 44 | 44 |
| Table cell horizontal padding | 16 | 12 |
| Control height (inputs, buttons) | 40 | 36 |
| Gap between a label and its control | 8 | 6 |
| Gap between form fields | 24 | 16 |
| Nav item height | 40 | 36 |
| Brand block height | 56 | 48 |
| Prose and input text size | 16 | 14 |

## The radius steps

Each step sets Tailwind's radius variables so every `rounded-*` in Flux follows at once.

| Variable | Sharp | Soft | Round |
|---|---|---|---|
| `--radius-sm` | 2 | 4 | 6 |
| `--radius-md` | 3 | 6 | 8 |
| `--radius-lg` | 4 | 8 | 12 |
| `--radius-xl` | 6 | 12 | 16 |
| `--radius-2xl` | 8 | 16 | 24 |

Values in pixels. `rounded-full` is unaffected and stays a pill or circle. Flux uses `rounded-lg` for buttons, inputs and cards and `rounded-md` for smaller controls, so the step is felt everywhere without touching a component.

## Rules

- Borders are one pixel. Two pixel borders exist only for the accent bar motif and for focus rings.
- Shadows are not used for elevation on surfaces. Cards are marked by border or by hairline division. The only shadows are on overlays: dropdowns, modals and toasts, at one fixed value, `shadow-lg`.
- Max content width in the top nav shell is 80rem. The sidebar shell has none.
- Touch targets are never under 36px in either density. Flux's only button size that clears this is `base`, at 40px: `sm` is 32px and `xs` is 24px, so neither is used. An inline action in a dense row is `base` like any other button, or it is not a button.


---

# 06. Components

The component inventory is fixed and is not something a project or a generator chooses. It is Flux's free set plus five custom components: page header, stat tile, stat row, empty state and badge, and the frame for an app that does not draw its shell with Flux. Page titles go through the page header component, because that is what applies the pairing's second face at title size.


Each entry below is the Flux component and the rule the standard adds: what it is for, what the standard fixes about it, and what it is never used for. A project does not add components; it asks for a change to the standard. The fleet does not install Flux Pro: where an entry below names a Pro component, it is there to say which free one takes its place, and `design:audit` reports a Pro tag on a screen. The coverage table at the end lists every component Flux ships so nothing is left to guesswork.

## Navigation

- **Sidebar** `flux:sidebar` with `sidebar.header`, `sidebar.nav`, `sidebar.group`, `sidebar.item`. Regions and limits are in 02. Active item styling is set by the motif (07).
- **The frame** For an app that does not use Flux, the sidebar shell is `x-ds::shell`, which takes the app's menu as an array and draws 02's regions from it. Its parts are `x-ds::account` for the user menu at the foot of the sidebar, `x-ds::theme-toggle` with `x-ds::theme-script` in the head for a light and dark switch, `x-ds::flash` for the page's status, warning and error messages, and `x-ds::fonts` for the style's faces. The flash is the badge's treatment at page width: the status's soft tint and a two pixel rule in its colour. An app never draws its own sidebar beside it, and `Dashcore\DesignKit\Testing\Shell::assertConforms()` fails a page that does.
- **Navbar** `flux:navbar` and `flux:navbar.item` for the top nav shell only.
- **Navlist and navmenu** `flux:navlist`, `flux:navmenu`. Navlist is the vertical list inside the sidebar; navmenu is the dropdown form of a nav for the top nav shell on small screens. Navmenu never appears in content. Navlist appears in content in one case only: a section with its own destinations, such as settings, where it sits vertically beside the panel it switches and carries one level with no nesting. That is a sub-navigation and never a second main nav, so the sidebar or top nav still says which section you are in.
- **Header** `flux:header` appears below `lg` in the sidebar shell, where it carries the drawer toggle, and always in the top nav shell.
- **Breadcrumbs** `flux:breadcrumbs`. Only at three or more levels deep.
- **Tabs** are Pro and not used. A section with its own destinations takes a navlist beside the panel, as above; two to three options that behave like a toggle take a segmented `flux:radio.group`. Either way the selected item is marked in ink, `text-strong` with a two pixel `text-strong` rule beneath it or beside it, never in accent: the accent marks the page's primary action and the main nav's "you are here", and a selected tab spending it as well puts two accents on one screen. Never more than six, never nested, and never holds a primary action.
- **Command palette** `flux:command` is Pro and not used. Navigation is the sidebar's.

## Text

- **Heading** `flux:heading` at the sizes in 03. Level is set by role in the page, not by importance of the words.
- **Text** `flux:text` for body and descriptions. Muted variant takes `text-muted` with the sunken correction from 04.
- **Subheading** `flux:subheading` is the one-line description under a page title. One sentence.
- **Description, label, legend, error** `flux:description`, `flux:label`, `flux:legend`, `flux:error`. Label above the control in `text`, description below the label in `text-muted`, error below the control in danger text, one line. Legend is a fieldset's heading at the section heading size.

## Actions

- **Button** `flux:button`. Four variants and no others:
  - Primary: accent fill, `on-accent` text. One per view region. Never two in a row.
  - Default: `surface` fill, `border-strong` outline, `text`. Any number.
  - Ghost: no fill, no border, `text`. For toolbars and icon buttons.
  - Danger: an outline. Danger text, a one pixel danger border, no fill; hover takes the danger soft tint. Never a solid red fill: a filled red button is the loudest thing on any screen, and a destructive action is never the one a screen should shout. Only for destructive actions, never as the default focus in a modal, and never the page's primary action. The markup is plain `variant="danger"`; `design:apply` puts the outline in the app's CSS, so nothing is written on a screen.
  - Sizes follow density: `base` in comfortable, `sm` in compact. Icon buttons are square. `xs` only inside table cells.
- **Button group** `flux:button.group`. Two to four default buttons that act on one thing. Never mixes variants.
- **Link** `flux:link`. `accent-content` colour, underline on hover only. Links are for navigation. Actions are buttons.
- **Dropdown and menu** `flux:dropdown`, `flux:menu`, `menu.item`, `menu.separator`, `menu.submenu`, `menu.radio`, `menu.checkbox`. Items are 14px, icon plus label. Destructive items last, separated, in danger text. One level of submenu at most.
- **Context menu** `flux:context` is Pro and not used. A row's or card's actions go in a `flux:dropdown` on a ghost icon button, with the menu rules above.
- **Popover** `flux:popover` is Pro and not used. One phrase is a tooltip; anything longer is a `flux:dropdown`, and a form or a primary action is a modal.
- **Tooltip** `flux:tooltip`. Only for icon buttons without a visible label. One short phrase.

## Forms

- **Field and fieldset** `flux:field`, `flux:fieldset`. Field wraps one control with its label, description and error. Fieldset groups related fields under a legend. A form of more than eight fields is split into fieldsets. Forms in the content grid span eight columns with four columns of notes or empty space, and go full width and one column below `lg`, as 12 says.
- **Input, textarea, select** `flux:input`, `flux:textarea`, `flux:select`. `surface` fill, `border-strong` outline, radius from the step. Focus is a two pixel accent ring with a two pixel offset in `page`. Placeholder is `text-muted` and is never the only label. Input groups (`input.group`) for a prefix or suffix only, never to join two fields.
- **Select variants** The listbox and combobox variants, `flux:autocomplete` and `flux:pillbox` are Pro and not used. One of many is a plain `flux:select`; many of many is a `flux:checkbox.group`.
- **Checkbox, radio, switch, toggle** `flux:checkbox`, `flux:radio`, `flux:switch`, `flux:toggle`. Checked state is an accent fill. Label to the right. Switch for a setting that takes effect at once; checkbox for a choice saved with the form; toggle for a pressed state in a toolbar. Card variant of checkbox and radio only when each option needs a description.
- **Slider** `flux:slider` is Pro and not used. A number is a `flux:input type="number"`.
- **Date and time** `flux:input` of type `date`, `time` or `datetime-local`. The Pro date picker, time picker and calendar are not used. A range is two inputs, from and to, in one field group.
- **Colour** `flux:input type="color"`, not the Pro colour picker. Only in settings that genuinely set a colour, such as a label colour. Never a way for a user to override the design.
- **Long text** `flux:textarea`, not the Pro editor, in `--ds-prose` size at the density's prose value. Where formatting matters it takes Markdown and renders it in the same prose style.
- **Chat or note input** `flux:textarea` with a ghost attach button and a primary send, not the Pro composer. One per view.
- **File upload** `flux:input type="file"`, not the Pro file upload. One line of instruction in the description; the chosen file named under it in `text-muted`; errors in danger text under the field.
- **OTP** `flux:otp`. Six cells, tabular numerals, accent focus ring. Only on the two-factor page.

## Data

- **Table** `flux:table`, `table.columns`, `table.column`, `table.rows`, `table.row`, `table.cell`. Head row sits on `sunken`, 12px labels, `text-muted`. Body rows on `surface`, 14px, separated by one pixel `border`. Row hover is `sunken`. The first column is `text-strong`. Numbers right aligned and tabular. Row height from density. A table with more than twenty-five rows paginates. Tables scroll horizontally inside their own container and never widen the page. Sortable columns show the arrow only on the sorted column. None of that is written on a screen: `design:apply` puts it in the app's CSS, so a table is a plain `flux:table` and the styling arrives with the package. A view that reaches into `data-flux-column` or `data-flux-cell` to say it again is reported, and it stops matching the moment the adapter moves.
- **Pagination** `flux:pagination`. Below the table, right aligned.
- **Badge** `x-ds::badge`, not `flux:badge`. A tag, not a pill: `radius-sm` corners and a two pixel rule on its leading edge. With a `status` of success, warning, danger or info it takes that status's soft background, its text, and a rule in the same colour as the text. Without one it is neutral: sunken background, muted text at the step 04 measures against a quiet surface, and a `border-strong` rule. Neutral carries categories, roles, plans, types, and any state the four colours do not cover, such as rejected, retired or not configured. Colour on a badge means status and nothing else, so a category never takes one and never the accent, and an unrecognised status renders neutral rather than being painted a colour that would say something untrue. A count is not a badge: a number beside a label is text. One badge per cell. Flux's badge paints from Tailwind's palette across six steps per colour, so it shows a different green from the standard's and matching it would mean repainting four colour families for the whole app. The custom badge reads the roles instead.
- **Avatar** `flux:avatar`, `avatar.group`. Initials on `sunken` when there is no image. Never in accent. Groups overlap by a quarter and stop at four plus a count.
- **Progress** `flux:progress`. Track is `sunken`, fill is accent. Determinate only; for unknown duration use a skeleton or a spinner in a button. A bar measured against a limit, a budget or a quota, may fill in the status it reports instead: `color="red"` over the limit, `amber` close to it, `green` within it. The style turns those three into the status roles. No other colour.
- **Chart** `flux:chart` is Pro and not used, and the standard has no chart yet. Numbers go in a table or stat tiles. A screen that genuinely needs a chart asks for a rule, written against that screen.
- **Hand-drawn SVG**, a sparkline or a diagram. Marks are coloured from the roles so they follow the register: a class that reads one (`stroke-(--ds-danger)`), or `style="stroke: var(--ds-danger)"` where a class cannot reach. Never a hex, and never a presentation attribute with `var()` in it (`stroke="var(…)"`), which some renderers ignore.
- **Code and command output** A `pre` on `sunken`, `text` colour, one pixel `border`, `radius-sm`, 14px in the pairing's mono face if it has one and the system mono otherwise. Never a black terminal block: on a light screen it is the loudest thing there.
- **Timeline** `flux:timeline` is Pro and not used. History is a list: events in `text`, times in `text-muted` tabular, a one pixel `border` line, the current event marked in accent. For history and audit, never for navigation.
- **Kanban** `flux:kanban` is Pro and not used.
- **Folded sections** native `<details>` with a `<summary>`, not the Pro accordion. Heading in `text-strong`, chevron in `text-muted`, one pixel `border` between items. For reference content; never to hide a form's required fields.
- **Carousel** `flux:carousel` is Pro and not used.
- **Skeleton** `flux:skeleton` for loading states longer than 300ms. Shapes match the content they stand in for.

## Surfaces and feedback

- **Card** `flux:card`. `surface`, one pixel `border`, radius from the step, padding from density. A card holds one thing. Cards are never nested.
- **Callout** `flux:callout`, `callout.heading`, `callout.text`, `callout.link`. Status colours only, soft style, an icon, one heading line, one text line, at most one action. A neutral callout uses `sunken` and `text` for notes that are not a status. The action is `callout.link`, an inline underlined link; Flux has no `callout.button`.
- **Modal** `flux:modal`, `modal.trigger`, `modal.close`. Max width 32rem for confirmations, 48rem for forms. Title, body, actions right-aligned with the primary last. Closes on escape and backdrop. Flyout variant slides from the end edge for detail views; never both on one screen.
- **Toast** `flux:toast`. Bottom right, status coloured, dismisses itself after five seconds unless it has an action. Flux fixes the width at 384px, dropping to 320px below `sm` inside a group. That is Flux's number rather than ours and it is fine; it is written down here so nobody reopens it as a disagreement.
- **Separator** `flux:separator`. One pixel `border`. Used between regions only when the hairline motif is chosen; otherwise regions are separated by the section gap. With text only to label a group of items.
- **Profile** `flux:profile`. The user block at the bottom of the sidebar or end of the header: avatar, name, chevron. Nothing else.
- **Brand** `flux:brand`. The brand block from 02. Logo or initial in an accent square, name in `text-strong`.
- **Spacer, container, main, aside, footer** `flux:spacer`, `flux:container`, `flux:main`, `flux:aside`, `flux:footer`. Layout primitives only. Container width is 80rem in the top nav shell. Footer is not part of either shell; aside is the chat or detail panel beside main and is 27rem wide.

## Custom components

The five components every fleet app needs that Flux does not ship, or does not ship on the standard's colours. They ship in the `dashcore/design-kit` package as Blade components and read only the `--ds-*` role variables and `config('brand')`.

- **Page header** `x-ds::page-header`. Eyebrow, title, description, actions, as defined in 02. Every page title goes through it. A bare `flux:heading` does not take the pairing's second face, so a serif or display pairing is invisible on any screen that skips this component.
- **Section heading** `x-ds::section-heading`, not `flux:heading`. The 18px, weight 600 role from 03, for a section heading, a card title or a modal title, in the body face. Flux cannot make it: `size="lg"` renders 16px at weight 500 and `size="xl"` renders 24px at 500 where 03 asks for 600, so its two largest sizes miss both of the standard's heading roles, and on weight every size misses. Its sizes are plain utility classes on the element, so passing a class of your own leaves two competing and the stylesheet order decides. `flux:heading` at the default size is 14px at 500, which is the UI emphasis role, and that one is right.
- **Stat tile** `x-ds::stat`. Label in the label style, value in the stat size and the display face if the pairing has one, an optional delta in success or danger text with an arrow icon, an optional one-line footnote in `text-muted`.
- **Stat row** `x-ds::stat-row`. The grid for two to four tiles, laid out by motif (07): a one pixel grid for hairline, the section gap otherwise.
- **Empty state** `x-ds::empty`. Centred in its region: one Heroicon at 24px in `text-muted`, one line saying what belongs here, one line saying how to add it, one default button. Never an illustration. Two sizes. The default fills a whole region and stands 320px tall. `size="compact"` drops the minimum height and the button, for a panel inside a split region, where the full size pushes everything below it off the screen.
- **Badge** `x-ds::badge`. One of the four statuses in soft style from the `--ds-*` roles with a leading rule in the status colour, or neutral when no status is given.

## Rules

- No component is styled per page. If a page needs a different table, the standard is wrong or the page is.
- Loading, empty and error states are designed for every list and every form, using skeleton, empty state and callout. A blank region is never a state.
- Icon-only buttons have a tooltip and an accessible label.
- Every component renders in both registers and is checked in the Components mockup screen before a design is exported.

## Coverage

Every component Flux ships as of 2.19, with where its rule lives. Free unless marked Pro, and a Pro row is not used: the next section names what replaces it. Internal helpers Flux uses to build other components are listed so the table is complete, not because a project uses them.

| Component | Tier | Rule |
|---|---|---|
| accordion | Pro, not used | Data |
| accent | free | internal, the accent wrapper Flux uses |
| aside | free | Surfaces |
| autocomplete | Pro, not used | Forms, select variants |
| avatar | free | Data |
| badge | free | Data |
| brand | free | Surfaces |
| breadcrumbs | free | Navigation |
| button, button.group | free | Actions |
| calendar | Pro, not used | Forms, date and time |
| callout | free | Surfaces |
| card | free | Surfaces |
| carousel | Pro, not used | Data |
| chart | Pro, not used | Data |
| checkbox | free | Forms |
| color-picker | Pro, not used | Forms |
| command | Pro, not used | Navigation |
| composer | Pro, not used | Forms |
| container | free | Surfaces |
| context | Pro, not used | Actions |
| date-picker | Pro, not used | Forms |
| description | free | Text |
| dropdown | free | Actions |
| editor | Pro, not used | Forms |
| error | free | Text |
| field, fieldset | free | Forms |
| file-upload, file-item | Pro, not used | Forms |
| flag | free | internal, country flags for phone inputs; not used |
| footer | free | Surfaces, not part of either shell |
| header | free | Navigation |
| heading | free | Text |
| icon | free | 08 |
| input | free | Forms |
| kanban | Pro, not used | Data |
| label, legend | free | Text |
| link | free | Actions |
| main | free | Surfaces |
| menu | free | Actions |
| modal | free | Surfaces |
| navbar | free | Navigation |
| navlist, navmenu | free | Navigation |
| otp | free | Forms |
| pagination | free | Data |
| pillbox | Pro, not used | Forms, select variants |
| popover | Pro, not used | Actions |
| profile | free | Surfaces |
| progress | free | Data |
| radio | free | Forms |
| select (listbox, combobox) | free; the Pro variants are not used | Forms |
| separator | free | Surfaces |
| sidebar | free | Navigation |
| skeleton | free | Data |
| slider | Pro, not used | Forms |
| spacer | free | Surfaces |
| subheading | free | Text |
| switch | free | Forms |
| tab, tabs | Pro, not used | Navigation |
| table | free | Data |
| text | free | Text |
| textarea | free | Forms |
| time-picker | Pro, not used | Forms |
| timeline | Pro, not used | Data |
| toast | free | Surfaces |
| toggle | free | Forms |
| tooltip | free | Actions |
| with-container, with-field, with-inline-field, with-reversed-inline-field, with-tooltip | free | internal wrappers |

## Flux Pro

The fleet does not install Flux Pro. Every app is on free Flux, so a fresh clone and a CI run install without a licence, and no screen depends on a component one app has and the next does not. `design:audit` reports a Pro tag on a screen, with the replacement below, and `design:apply` warns when it finds Pro installed.

| Pro | Use instead |
|---|---|
| tab, tabs | a navlist beside the panel, or a segmented `flux:radio.group` for two or three options |
| calendar, date-picker, time-picker | `flux:input` of that type |
| color-picker | `flux:input type="color"` |
| file-upload, file-item | `flux:input type="file"` |
| popover, context | `flux:dropdown`, or `flux:tooltip` for one phrase |
| autocomplete, select listbox and combobox | `flux:select` |
| pillbox | `flux:checkbox.group` |
| accordion | native `<details>` sections, each with a heading |
| editor, composer | `flux:textarea` |
| slider | `flux:input type="number"` |
| timeline | a list, as in Data above |

Carousel, chart, command and kanban have no replacement. No screen in the fleet needs one. If a screen ever needs a chart or a kanban, that is a rule to write then, against the real screen, rather than a guess now.


---

# 07. Motifs

A motif is one structural habit that runs through a whole project and gives it a recognisable hand without changing the components. The generator picks exactly one. A project never combines two. A motif never adds a component or a colour; it only redistributes what the standard already allows.

- **Accent bar.** A two pixel accent bar marks the active nav item, the selected row and the active tab, with no background fill. Suits the sidebar shell, dense tools, and a saturated accent that would overwhelm as a fill.
- **Hairline.** Regions and rows are separated by one pixel lines instead of card boxes. Suits data-heavy screens, compact density and sharp radius.
- **Eyebrow.** Every page title and stat tile carries an uppercase label above it, in the pairing's mono face if it has one. Suits mono pairings, operations and technical work, anything with reference codes.
- **Filled stat.** The first stat tile is filled accent, the rest tinted, and active nav items take a soft accent background. Suits the top nav shell, comfortable density, round radius, and people-facing apps where one warm block of colour reads as welcome rather than alarm.

If neither the brief nor the generator has a reason to prefer one, the default is accent bar for the sidebar shell and filled stat for the top nav shell.


Each motif is defined by where it appears and what it forbids, so it can be checked in the dashboard mockup.

## 1. Accent bar

The active nav item carries a two pixel accent bar on its leading edge, full item height, and its label turns `text-strong`. No background fill on the active item. The same bar marks the selected row in a table. A selected tab is marked in ink (06), whatever the motif. Page header has no bottom border.

Forbids: filled active nav items, accent-soft row selection.

## 2. Hairline

Cards lose their border and radius and sit flush; the grid between stat tiles is a one pixel line of `border` with tiles on `surface` inside it. Page header has a one pixel bottom border. Tables have no outer border, only row lines.

Forbids: bordered cards, shadows anywhere except overlays, gaps between stat tiles.

Closest of the four to the retired OS Plus grid, kept because it earned it.

## 3. Eyebrow

Labels are 12px, 0.08em spacing, weight 500, `text-muted`, set in the pairing's mono face if it has one and the body face otherwise. Table heads are set the same way. Section headings inside cards are replaced by an eyebrow.

Forbids: mixed-case labels, section headings at 18px inside cards.

## 4. Filled stat

The stat row is the one place the accent is allowed as a surface. The first tile is filled `accent` with `on-accent` text; the rest are `accent-soft` with `text-strong` values. Active nav items take an `accent-soft` background with `accent-content` text.

Forbids: accent bars, accent-soft anywhere outside nav and the stat row.

## Rules

- The motif is chosen once. A screen that does not have a nav, a table or a stat row simply shows less of it.
- The suits lines above are guidance the generator reasons from, not hard pairings. A motif and a radius step are chosen separately and a person may overrule either.


---

# 08. Motion and icons

Motion marks a state change and nothing else. Icons are Heroicons, and only Heroicons. Nothing in a fleet app is illustrated except an optional hero image on sign-in.


## Motion

| What | Duration | Easing |
|---|---|---|
| Control state: hover, focus, checked | 150ms | ease-out |
| Overlay open and close: dropdown, modal, toast, drawer | 200ms | ease-out in, ease-in out |
| Skeleton shimmer | 1.5s loop | linear |

Hover states change colour, border or underline. They never change opacity, scale or position. A button that fades on hover is on the tells list.

Not used, anywhere: scroll-triggered fades or slides, parallax, cursor-following effects, pulsing dots, glowing borders, auto-playing carousels, animated gradients, count-up numbers.

`prefers-reduced-motion: reduce` turns every duration above to zero except the skeleton, which becomes a static block. This is not optional.

Alpine transitions and Flux's own hover states are the implementation. No animation library is added.

## Icons

Flux ships Heroicons and also resolves Lucide names, which is exactly how a project ends up mixing two sets. Lucide names are not used, and the adapter renames any it finds before deleting the four icon files the starter kit ships.

| Where | Style | Size |
|---|---|---|
| Nav items, buttons, page header actions | outline | 20 |
| Table cells, badges, inline with 14px text | mini | 16 |
| Empty state, callout | outline | 24 |
| Filled and checked states, status indicators | solid | matches context |

Icons take the colour of the text beside them. An icon never carries the accent on its own except inside the active nav item when the motif calls for it.

No emoji in headings, labels, buttons or nav. Emoji are allowed only in content the user typed.

## Imagery

Sign-in may carry one hero image supplied by the client. Empty states use an icon. Dashboards do not have banners.


---

# 09. Decisions and the JSON

This is the contract between the generator and everything downstream. The generator receives a brief and returns exactly the seven decisions below, each from its allowed list, plus a one-line reason for each. The resolver turns the decisions into tokens. The viewer and the adapter read only the tokens.

## The brief

Written by a person. The generator reads it; it does not fill it in.

| Field | Required | Notes |
|---|---|---|
| `client` | yes | Who the app is for. |
| `product` | yes | One or two sentences: what the app does and who uses it day to day. |
| `shell` | yes | `sidebar` or `top-nav`. A person decides this, per 02. |
| `brand_colour` | no | A hex the client already owns. If given, the accent is derived from it rather than chosen. |
| `logo` | no | A path to an SVG or PNG. Shown in the brand block and on sign-in. |
| `notes` | no | Anything else: an existing website, a mood, a thing to avoid. |

## The seven decisions

| Decision | Allowed values | How the generator should reason |
|---|---|---|
| `accent` | A hex. Hue at least 30 degrees from 0 and from 40; HSL saturation 45 to 85 percent. If `brand_colour` is given, use it or the nearest value that satisfies the rules. | The one colour that will mark every primary action. Read the client's field and audience. Avoid the hue every competitor in that field uses. |
| `temperature` | `pure`, `cool`, `warm` | Pure for finance and technical work. Cool for software and data. Warm for people, hospitality, health. Should sit well beside the accent. |
| `pairing` | An id from the approved list in 03 | Match the second face's kind to the content: mono when rows carry codes, serif when trust and calm matter, display when the client has some personality. |
| `radius` | `sharp`, `soft`, `round` | Sharp for dense data. Soft when there is no reason. Round for consumer and people-facing. |
| `density` | `comfortable`, `compact` | Compact only when trained staff use it all day. |
| `motif` | `accent-bar`, `hairline`, `eyebrow`, `filled-stat` | See 07 for what each suits. Never pick eyebrow with a pairing that has no mono face unless the brief is technical. |
| `copy` | `eyebrow` (up to 4 words, optional), `headline` (up to 8 words), `tagline` (one sentence, up to 16 words), `footer` (optional; defaults to a copyright line) | About the client and what the user came to do. No software words, no buzzwords, no exclamation marks. |

Each decision carries a `why`: one sentence, in plain language, that a person can agree or disagree with. The viewer shows it under the control.

## Constraints the generator must respect

- Every value comes from the lists above. There is no `other`.
- The seven decisions are the whole output. No extra colours, no extra fonts, no layout suggestions, no component changes.
- Two briefs that differ should produce decisions that differ in at least three of the seven, unless the briefs are near-identical.
- When the brief gives a `brand_colour` that fails the accent rules, the generator says so in `why` and returns the nearest passing value.


## The JSON

Two layers. `decisions` is what the generator wrote and a person may edit. `tokens` is what the resolver derived and nobody edits by hand.

```json
{
  "schema": "dashcore-design/1",
  "generated_at": "2026-09-11T10:00:00Z",
  "brief": {
    "client": "Harbor Point Physical Therapy",
    "product": "Front-desk and clinician scheduling for a three-location PT practice. Used all day by reception and therapists.",
    "shell": "sidebar",
    "brand_colour": null,
    "logo": null,
    "notes": "Older patients see the sign-in page. Nothing that looks like a bank."
  },
  "decisions": {
    "accent":      { "value": "#0f766e", "why": "A deep teal reads as clinical without being cold, and is far from the reds and ambers used for alerts." },
    "temperature": { "value": "warm", "why": "Warm greys soften a screen used by patients and staff in a care setting." },
    "pairing":     { "value": 10, "why": "Atkinson Hyperlegible Next for an older audience; the mono second face handles appointment codes." },
    "radius":      { "value": "soft", "why": "No reason in the brief to go sharp or round." },
    "density":     { "value": "compact", "why": "Reception uses it all day and works from the schedule table." },
    "motif":       { "value": "eyebrow", "why": "A mono pairing and a schedule full of codes suit uppercase labels." },
    "copy": {
      "eyebrow":  "Harbor Point",
      "headline": "Today's schedule, all three clinics.",
      "tagline":  "Sign in to see who is coming in and who is running late.",
      "footer":   null,
      "why": "Speaks to what reception opens the app to do."
    }
  },
  "tokens": {
    "fonts": {
      "sans":    { "family": "Atkinson Hyperlegible Next", "bunny": "atkinson-hyperlegible-next", "weights": [400, 500, 600], "fallback": "ui-sans-serif, system-ui, sans-serif" },
      "second":  { "family": "Spline Sans Mono", "bunny": "spline-sans-mono", "weights": [400, 500], "kind": "mono", "fallback": "ui-monospace, monospace" }
    },
    "radius": { "sm": "4px", "md": "6px", "lg": "8px", "xl": "12px", "2xl": "16px" },
    "density": {
      "gutter": "24px", "section": "24px", "card": "16px", "row": "44px", "control": "36px", "prose": "14px"
    },
    "neutral": {
      "50": "#fafaf9", "100": "#f5f5f4", "200": "#e7e5e4", "300": "#d6d3d1", "400": "#a8a29e",
      "500": "#78716c", "600": "#57534e", "700": "#44403c", "800": "#292524", "900": "#1c1917", "950": "#0c0a09"
    },
    "light": {
      "page": "#ffffff", "surface": "#ffffff", "sunken": "#fafaf9",
      "border": "#e7e5e4", "border-strong": "#d6d3d1",
      "text-strong": "#0c0a09", "text": "#292524", "text-muted": "#78716c", "text-muted-on-sunken": "#57534e",
      "accent": "#0f766e", "accent-hover": "#115e59", "accent-content": "#0f766e", "accent-soft": "#0f766e1a", "on-accent": "#ffffff"
    },
    "dark": {
      "page": "#1c1917", "surface": "#292524", "sunken": "#0c0a09",
      "border": "#44403c", "border-strong": "#57534e",
      "text-strong": "#fafaf9", "text": "#e7e5e4", "text-muted": "#a8a29e",
      "accent": "#14b8a6", "accent-hover": "#2dd4bf", "accent-content": "#2dd4bf", "accent-soft": "#14b8a629", "on-accent": "#0c0a09"
    },
    "status": {
      "success": { "text-light": "#15803d", "text-dark": "#4ade80", "fill": "#15803d" },
      "warning": { "text-light": "#b45309", "text-dark": "#fbbf24", "fill": "#b45309" },
      "danger":  { "text-light": "#b91c1c", "text-dark": "#f87171", "fill": "#dc2626" },
      "info":    { "text-light": "#1d4ed8", "text-dark": "#60a5fa", "fill": "#1d4ed8" }
    },
    "contrast": {
      "passes": true,
      "lowest": { "pair": "text-muted on page, light", "ratio": 4.8 }
    }
  }
}
```

The `contrast` block is written by the resolver after it measures every text role against its surface in both registers. A file with `passes: false` is not exported.

## The export pack

The viewer exports a zip, not a bare JSON, because two different things consume it: an artisan command that does the mechanical part, and an agent coder that does the judgment part and keeps the project on standard after day one. The pack has four files:

| File | For | Contents |
|---|---|---|
| `design/design.json` | The command and the agent | The file above. |
| `design/STANDARD.md` | The agent | Every section of this standard but the audit, 01 to 13, concatenated and whole. |
| `design/CLAUDE.snippet.md` | The target repo's CLAUDE.md | A short block to paste in: before building or changing any screen, read `design/design.json` and `design/STANDARD.md`, use only the roles, components and motif they name, and run the contrast check. |
| `design/MIGRATION.md` | Existing apps only | The rejected-by-name list from the audit as a checklist: replace placeholder layouts, swap Lucide names, lift hardcoded colours onto roles, adopt the three custom components, fix dark muted text. |

The `design/` folder is committed to the target repo. From then on every agent session in that repo builds against it without anyone restating the design.

For a new project: unzip into the fresh kit, run the command below, commit `design/`. For an existing Flux app: unzip, run the command for the retheme, then work through `MIGRATION.md` with an agent.

## What the adapter does with it

In a fresh Laravel Livewire Flux kit, one artisan command reads the file and:

- Writes `--color-zinc-50` through `950` from `tokens.neutral`, `--color-accent`, `--color-accent-content`, `--color-accent-foreground` for light in `@theme` and for dark on `.dark`, the `--radius-*` variables, `--font-sans` and `--font-mono` or `--font-display`, and the `--ds-*` roles, into `resources/css/app.css` between two marker comments.
- Replaces the Bunny font entry in `vite.config.js` with the two faces and their weights.
- Writes `copy` into the `.env` keys that `config/brand.php` reads.
- Copies the logo to `public/` if one was given.
- Removes the four Lucide-named icon files the kit ships.

Nothing else in the kit changes. Running it twice with the same file is a no-op.

## A style instead of a design

A Dashcore app takes the `dashcore` style (14) rather than a design of its own. Its `design/design.json` names the style and holds nothing else:

```json
{
  "schema": "dashcore-design/1",
  "style": "dashcore"
}
```

`php artisan design:apply --style=dashcore` writes that file, and points the app at the package rather than copying anything out of it:

- Two lines in `resources/css/app.css`, straight after Tailwind: an import of the style's stylesheet from `vendor/dashcore/design-kit/dist/`, and the `@source` line for the package's views. An applied design block, or `dashcore/ui`'s two lines, are removed.
- The frame's script in `resources/js/app.js`, in place of `dashcore/ui`'s.
- The two faces, where a design's would go.
- `DESIGN_MOTIF`, `DESIGN_SECOND_KIND` and `DESIGN_CONDENSED` in `.env`. Sign-in copy is the app's own and is left alone.

The style's values live in the package and nowhere in the app, so a release of `dashcore/design-kit` changes every app on the style at once, with nothing to re-apply. `design:update` on an app on a style re-applies it and audits.

## The allowance

An app joins the standard where it is, not where it should be. `php artisan design:audit --baseline` records what the audit finds that day, by rule, in `design/allowance.json`, and from then on `design:audit` fails only when a rule's count rises above it. The file is committed.

```json
{
  "known": ["Flux badge", "hardcoded colour", "…every rule the package knew"],
  "rules": { "hardcoded colour": 14, "spacing off the scale": 31 }
}
```

The allowance only shrinks. Recording it again keeps the lower count for every rule, so a screen fixed stays fixed, and raising a count is an edit to the file that a reviewer sees. A rule the package knew and the app had none of is held at zero. A rule a newer release added, which `known` does not list, is allowed at whatever it finds until the app records it, so a new rule never turns a build red on the day it ships. `--report` never fails, allowance or not.


---

# 10. Email

Every app in the fleet sends mail, and every one of them currently sends Laravel's default template. An owner gets a portal built to this standard and then an email that looks like nothing to do with it.


Email is the one surface where none of the rest of this standard applies directly. There are no custom properties, no utility classes, no second typeface and no dark register. What survives is the accent, the neutral scale, the voice and the structure. Everything else is rebuilt in inline styles, because that is all an email client will honour.

## What an email client will not do

Read this first, because most email mistakes are a rule from another section applied where it cannot work.

- **No CSS variables.** `var(--ds-accent)` is an empty string in Outlook. Every colour is a resolved hex, written inline on the element.
- **No stylesheet.** No Tailwind, no classes, no `app.css`. Styles are inline attributes.
- **No web fonts.** The pairing's faces do not load. The second face never appears in an email at all.
- **No reliable dark mode.** Clients invert on their own terms and disagree with each other. An email is built in one register.
- **Images are blocked by default.** Nothing an email needs to say may live only in an image, the brand mark included.

## Structure

One column, 600 pixels at most, centred on a `page` background. Nothing beside anything else; a phone is the common case and two columns collapse badly.

In order:

1. **A preheader.** One sentence, hidden in the body, which the inbox list shows beside the subject. Without it the client picks the first words it finds, which is usually the brand name repeated.
2. **The brand**, as text in the body face. Not an image, because images are blocked.
3. **One message.** A short heading saying what happened, then what it means for the reader, in sentences.
4. **One action**, when there is one. A single button. An email with two competing buttons has no action.
5. **A footer.** Who sent it, why this person received it, and how to stop. Muted text, 12px minimum.

An email carries one subject. Two things worth telling somebody are two emails.

## Type

The body face only, with a system fallback stack after it, because the face will not load and something has to render.

| Role | Size | Weight |
|---|---|---|
| Heading | 20px | 600 |
| Body | 16px | 400 |
| Footer | 12px | 400 |

Body text is 16px rather than the 14px of an app screen. Mail is read at arm's length, on a phone, once.

Line height 1.5 on body text. Left aligned, never justified, and never centred beyond a heading.

## Colour

Resolved hex, inline, from the project's own design file.

| Where | Role |
|---|---|
| Page background | `page` |
| Card background | `surface` |
| Body text | `text` |
| Headings | `text-strong` |
| Footer and secondary | `text-muted` |
| Rules and borders | `border` |
| The one button | `accent` filled, `on-accent` text |
| A status line | the matching status role, as text |

The accent appears once, on the button. A status email may use a status colour as text, on the line that carries the status, and nowhere else.

Never assume the reader's background. A client that inverts should still leave the email legible, so no white text on an image and no colour that only works against one backdrop.

## Buttons

A bulletproof button, built as a table cell with the background on the cell rather than on a styled anchor. A CSS-styled link renders as plain text in Outlook, and a primary action that arrives as plain text is a broken email.

The label says the action: "Pay the invoice", not "Click here". The full URL goes in the footer as text too, for the clients that strip the link.

## Voice

The subject line says what happened, in the words the product uses on its own screens. No urgency that is not real, no emoji, no branding language.

The first sentence repeats it in full, because a notification is often read in a preview pane and never opened.

## What the adapter ships

`<x-design-kit::mail>` is the layout, and it reads the resolved values from `design/design.json` rather than from CSS, so an email matches the app without anything being typed twice. Section 06's components are app components and none of them render in an email.


---

# 11. Error and empty-app pages

The pages a person reaches when something has gone wrong, or before there is anything to see. Laravel ships its own, and a fleet app that has never touched them is showing a framework's page under a product's name.


These pages are rare, so they are the last thing anybody styles and the first thing that gives an app away. They also arrive at the worst moment, when somebody is already stuck.

## The error pages

Laravel renders 404, 419, 429, 500 and 503. All five take the same layout, and only the words change.

Centred in the page, on `page`, with no shell. There is no sidebar and no top nav, because the page that failed may be the one that draws them.

In order: the brand as text, one short heading, one or two sentences, and one default button back to somewhere that works. No illustration, no large number set in the display face, no apology in three paragraphs.

| Code | Heading | What the sentence says |
|---|---|---|
| 404 | Nothing here | The address is wrong or the thing has been removed. Offer the way back. |
| 419 | That page expired | They sat too long. Ask them to try again; nothing was lost. |
| 429 | Too many attempts | Say how long until they can try again, if the number is known. |
| 500 | Something broke | Say it is ours, not theirs, and that it has been recorded. Never show the exception. |
| 503 | Back shortly | Planned work. Say roughly how long if it is known. |

Say what happened in the words a person would use. "Nothing here" rather than "404 Not Found". The code goes in muted text under the button, for somebody reporting it.

**Never show a stack trace, a file path, a query or an exception message on a page a customer can reach.** That rule is not about design.

## Status colour

None of these pages uses `danger`. A red page tells somebody their data is gone. A 404 is a wrong address, and a 500 is our fault and already logged. The heading is `text-strong` and the page is neutral.

The one exception is a page reporting real data loss, and that wording needs a person, not a template.

## First run

The state where the app is working and there is nothing in it yet. It is not an error, and it is where most apps show an empty shell and let the person guess.

Use `x-ds::empty` in the main region, at the full size, with the button that creates the first thing. The sidebar or top nav stays, because it is the map. Never a modal on first load.

Where the app needs several things set up before it is useful, the first-run screen names them as a short list with the state of each, and does not pretend the app is ready.

## Sign-in as a dead end

A person who follows a link while signed out lands on sign-in. After signing in they go where the link pointed, not to the dashboard, because sending them to the dashboard loses what they came for.

If the link has expired or the account cannot reach it, say which, on the sign-in page, in the status role that fits. Never a blank redirect.

## What the adapter ships

`design:apply` publishes the five error views into `resources/views/errors/`, taking the design's own colours and body face. They are ordinary Blade files afterwards and the wording is meant to be edited per product.


---

# 12. Widths

The rest of this standard describes screens at one width and then says "on mobile" without saying what that means. Three people read that and pick three widths. This section names them.


## The scale is Tailwind's

`sm` 640, `md` 768, `lg` 1024, `xl` 1280, `2xl` 1536.

Not because they are the right numbers in the abstract, but because Flux is built on them. Naming our own would mean fighting a component library in every app in the fleet, for a set of numbers nobody would notice. The word in this standard is the word a developer types.

## The fleet designs at three widths

Not five. Fifteen admin tools do not earn five layouts, and a width nobody checks is a width that is wrong.

| Name | Range | What it is |
|---|---|---|
| Phone | below `md` | A customer on a phone. One column. |
| Tablet | `md` to below `lg` | A tablet, or a narrow window on a laptop. |
| Desktop | `lg` and up | Staff, and the width every screen here is drawn at. |

Nothing new happens above `lg`. Content is already capped at 80rem by 02, so a large monitor centres the same layout rather than stretching a line of text across it.

A screen is finished when it has been looked at in all three. Not resized past, looked at.

## The shells

**Sidebar** is a drawer below `lg`, opened from a header that exists only below that width. It is not narrowed and it is not hidden. A nav that disappears leaves somebody with no map.

**Top nav** keeps its items inline down to `md`. Below that they move into `flux:navmenu` behind a toggle, and the brand block stays visible.

The two differ on purpose. Five nav items fit across 768px; a 16rem sidebar beside content does not.

## What changes at each width

**Page header.** Actions wrap below the title below `md`. The title never truncates to make room for them.

**Stat row.** Two across below `lg`, four at `lg` and up. Two tiles at phone width is tight and still readable; three is not. If a row has three tiles, it is two and then one, not three squeezed.

**Sign-in.** The brand panel sits above the form below `lg`, carrying the wordmark and the headline only. The tagline and footer line are dropped rather than stacked, because a phone sign-in is a form and everything above it is delay.

**Forms.** Fields are one column below `md`. Two columns are allowed from `md`, where each column still holds a label and its control without wrapping. A two-column form at phone width is two narrow columns, which is worse than long. Below `lg` the form spans the full content width rather than the eight columns 06 gives it, since there is nothing beside it to make room for.

**Cards and regions.** A row of regions becomes one column below `md`. Below `lg` it may stay two if each half still holds its content without wrapping a heading.

## Tables

The hard one, and the answer depends on who opens the screen.

**A table a customer reaches stops being a table below `md`.** Each row becomes a block: the value that identifies the row as its heading, the rest as label and value lines beneath. Six columns at 375px is not a table anybody can read, and sideways scrolling on a phone hides the columns that made it a table.

**A table only staff reach may scroll sideways below `md`**, inside its own container rather than the page. This is allowed because nobody opens it there, and building a stacked view for a screen nobody opens on a phone is work that will not be used.

Which one applies is a per-screen decision and belongs in that screen's commit message. Where it is not obvious, stack it: the cost of stacking a staff table is a little markup, and the cost of scrolling a customer table is a customer who cannot read it.

Neither version drops a column. A column worth showing at `lg` is worth reaching at `md`.

## What the audit can and cannot see

It can see a fixed width or a layout that names no breakpoint at all. It cannot tell you a screen was never opened at phone width, and it cannot judge whether a stacked table reads well.

So this section is checked by looking. The tool shows a design at each of the three widths for that reason.


---

# 13. Public pages

The pages a signed-out person reaches: the landing page, pricing, legal, contact. They are not part of what this standard unifies. The standard is for the signed-in back end, the screens a team works in every day, and a public page keeps its own brand.


A public website, a careers page, a product's marketing pages: each of those has an audience and a brand of its own, and holding them to the back end's style would be the wrong job for this standard. What the fleet shares is the experience inside, so that is what it unifies.

Sign-in is not a public page for this purpose. It is the door to the back end, on the roles and the scale, and it is in 02 and 11.

## Advice, not rules

The rest of this section is advice for anyone building a public page from a design. None of it is audited. It is kept because a public page is the surface most likely to arrive looking like every other generated page on the internet, and these are the things that make it look that way.

## What carries over, when a page is built from a design

Everything that is the brand rather than the furniture.

- **The accent and the neutral ramp**, through the `--ds-*` roles exactly as an app screen uses them. Both registers come for free, so a public page is themed rather than painted.
- **The type pairing**, both faces, with the second face doing what 07 says the motif does.
- **The spacing scale and the radii** from 05.
- **The icon set** from 08. One set, the app's set.
- **The three widths** from 12, and the 80rem content cap from 02.
- **The voice.** Concrete sentences about what the product does. The same rule as everywhere else.

## What does not

- **The shell.** No sidebar, no app top nav. A public page has its own header and footer, and they are not the app's.
- **Density.** Row heights, control heights and table rules are app things. A public page is read, not worked in.
- **`x-ds::page-header`.** That component is the app's page title region. A landing page has a hero, which is a different thing with a different size.

## The shape

In order, and short:

1. **A header.** The brand mark, at most four links, and one action. The action is Sign in, or Get started where there is a signup.
2. **One hero.** One heading, one sentence under it, one button. Nothing above the heading.
3. **What it does.** Three or four claims, each one concrete and each one tied to something real. A screenshot of the actual product beats an illustration of an idea.
4. **One more action**, at the bottom, the same action as the hero.
5. **A footer.** Legal, contact, status, and who owns the thing.

A page that needs more than this is usually a page that has not decided what it is for.

## Type at public scale

03's scale is built for app screens and stops at `text-2xl`. A hero needs more, and this is the one place it is allowed.

| Role | Size | Weight |
|---|---|---|
| Hero heading | 48px, 36px below `md` | 600 |
| Hero sentence | 18px | 400 |
| Section heading | 30px | 600 |
| Body | 16px | 400 |

Nothing goes above 48px. A heading larger than that is a poster, and a poster is not a product page.

## What to avoid

These are the tells of a page nobody decided. Any one of them weakens a page, and any two of them together make a page that could belong to anybody.

- No gradient as a background, and never a purple-to-blue one.
- No gradient fill on text. A heading is one colour.
- No emoji in a heading.
- No badge, pill or chip above the hero heading.
- No glassmorphism: no blurred translucent cards over a busy ground.
- No grain, noise or mesh over a gradient.
- No three icon boxes in a row as the way features are shown.
- No fade-in on scroll, no cursor-following beam, no element that moves because the page loaded. 08 governs motion here too, and motion needs a reason.
- No serif italic used as an accent on a sans page.
- No copy that could be about any product. If the sentence would survive swapping the product name, rewrite it.

Every one of them is something a generator reaches for when nobody has said otherwise, and the point of this section is to say otherwise.

## Legal pages

Terms, privacy and anything else long. One column, body face, no hero, and the 65-character line cap from 03, which is `max-w-prose`. Headings step down normally and the page is readable at `md` without a sidebar of contents.

`--ds-prose` is not that cap. It is the prose type size from 05, and using it as a width gives a column 14 pixels wide.

These are not marketing pages and they do not take the landing header. The brand mark and a way back is enough.

## What the audit checks

Nothing. A public page says so once, at the top, and the audit leaves the whole file alone:

```blade
{{-- @ds-public --}}
```

A marker rather than a folder name, because public is a decision about who reaches a page and no path spells that reliably. Laravel's own `welcome.blade.php` is the exception and needs no marker, since every app has one and it is always the landing page.

The marker is a statement, not a loophole: it says a page is for signed-out people. A back-end screen carrying it is drift the audit cannot see, so it goes on public pages only. `@ds-audit-ignore-file` stays for files outside the standard for another reason, a PDF or an email, with that reason beside it.


---

# 14. The Dashcore style

Dashcore's own apps take one fixed style, named `dashcore`. It is not a choice a generated design can make, and nothing below is an option for one.


## Who takes it

Every app in the fleet catalogue: `Catalogue::APPS` in `dashcore/bridge`, 24 apps besides this one. They are tools for the people who run Dashcore, and they should read as one product. A client project never takes it; a client gets its own seven decisions.

Everything in 01 to 13 holds for these apps exactly as for any other. The style fills in the seven decisions, and makes four exceptions to the rules a generated design is held to. The exceptions are listed below with the reason for each, and they belong to this style alone.

## The seven decisions

| Decision | Value |
|---|---|
| `accent` | `#4a4438` in light, `#d9cbb0` in dark. Exception 1. |
| `temperature` | Its own warm set, given as roles below rather than a scale. Exception 2. |
| `pairing` | Inter for the body, Fraunces as the serif second face. Exception 3. |
| `radius` | Sharp, capped at 4px. Exception 4. |
| `density` | `comfortable` |
| `motif` | `accent-bar` |
| `copy` | Each app writes its own. The sign-in words are about what that app is for, so they are the one decision the fleet does not share. |

Shell: `sidebar` for every app with a signed-in area. website is the public site and follows 13.

## The exceptions

**1. A near-ink accent.** 04 asks a generated accent for 45 to 85 percent saturation, because under that it reads as grey. Here that is the point: the accent marks the one primary action and the main nav's "you are here", and it does that by weight rather than hue. `#4a4438` sits below the band. The dark accent is stated rather than derived, because 04's derivation lightens toward a hue this accent barely has.

**2. Its own neutrals.** 04's warm temperature is Tailwind's stone, with a white page. This style keeps a warm, off-white page and a white surface, so panels lift off the page without a shadow. The roles are given directly, below, and are measured like any design's.

**3. Inter and Fraunces.** 03 excludes Inter by name, because a generated design that reaches for it reads as the starter kit, and retired its Fraunces pairing on 11 September 2026. Neither reason applies to a style a person chose and the whole fleet already wears. Fraunces follows 03's serif rule like any second face: page title and sign-in headline only, never under 24px, never at 700.

**4. A 4px ceiling.** Sharp in 05 still rounds to 8px at `2xl` and leaves `rounded-full` alone. This style caps every radius variable at 4px and clamps `rounded-full` too. A shape that really is a circle keeps it: a status dot, a spinner, a radio, a switch.

## Roles

| Role | Light | Dark |
|---|---|---|
| `page` | `#f7f4ef` | `#151412` |
| `surface` | `#ffffff` | `#1d1b18` |
| `sunken` | `#efebe2` | `#11100e` |
| `border` | `#d8d2c7` | `#2e2b26` |
| `border-strong` | `#b8af9f` | `#454039` |
| `text-strong` | `#1c1b19` | `#ece7dd` |
| `text` | `#1c1b19` | `#ece7dd` |
| `text-muted` | `#6b6560` | `#a39c90` |
| `text-muted-on-sunken` | `#6b6560` | `#aaa397` |
| `accent` | `#4a4438` | `#d9cbb0` |
| `accent-hover` | `#33301f` | `#e8dcc4` |
| `accent-content` | `#4a4438` | `#d9cbb0` |
| `accent-soft` | accent at 10% | accent at 16% |
| `on-accent` | `#ffffff` | `#1c1b19` |

`text` and `text-strong` are one ink. Weight and size separate a title from body here, not a second shade.

Status colours are the fleet's, from 04, unchanged.

## The Flux scale

Flux paints its components from the zinc scale, so the style stands its own neutrals in for zinc, each step the role nearest it:

| Step | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Value | `#f7f4ef` | `#efebe2` | `#d8d2c7` | `#b8af9f` | `#9b948a` | `#6b6560` | `#454039` | `#2e2b26` | `#1d1b18` | `#151412` | `#11100e` |

## What the floor measures out at

Measured by the same check as every design, with the accent tint laid over `surface` as 04 measures it. The style cannot be built if any text pair fails.

| Pair | Light | Dark |
|---|---|---|
| text on page | 15.69 | 14.94 |
| text on surface | 17.21 | 13.94 |
| text-muted on page | 5.24 | 6.76 |
| text-muted on surface | 5.74 | 6.31 |
| text-muted-on-sunken on sunken | 4.83 | 7.60 |
| text-muted-on-sunken on the accent tint | 4.87 | 4.73 |
| text-muted-on-sunken on a status tint, lowest | 5.25 | 5.87 |
| accent-content on the accent tint | 8.18 | 7.39 |
| on-accent on accent | 9.65 | 10.75 |
| status text on page, lowest | 4.57 | 6.66 |
| status text on its own tint, lowest | 4.79 | 5.78 |

Two values moved from the look the fleet wore under `dashcore/ui`, both because of this table. The light page was `#f5f2ec`, where success and warning text measure 4.49:1, under the floor; `#f7f4ef` clears it and is hard to tell apart by eye. And in dark, muted text on the accent tint measured 4.35:1, so muted text on a quiet surface takes a lighter step there, `#aaa397`.

## What it leaves to the standard

On 16 October 2026 `dashcore/ui`'s own rules and this standard were set side by side and every difference settled once. Where `dashcore/ui` differed on something the standard fixes for every app, either the standard's rule stands or `dashcore/ui`'s became the rule for every app. Nothing in this section overrides 01 to 13 except the four exceptions above.

- **The standard's rule stands:** the type scale in 03, including the 12px floor; status colours in 04; the sidebar at 16rem on `sunken` in 02.
- **`dashcore/ui`'s became everyone's:** a selected tab in ink, a destructive action as an outline, 44px table rows, and the badge as a tag with a leading rule. They are in 04 to 07 now, for every design.
