<?php

namespace Dashcore\DesignKit;

/**
 * Finds what in an app is off the standard. Pure: give it a file's contents,
 * get back findings. The command walks the tree and reports.
 *
 * It only reports what can be judged from the text of a file. Layout questions,
 * whether a stat row says the right things, whether a screen is composed around
 * its content, are for a person looking at the screen. Those live in the pack's
 * MIGRATION.md, not here.
 */
final class Audit
{
    /**
     * Every rule the audit can report, by the name it reports under. The
     * ratchet uses this to tell a rule an app has zero of from a rule this
     * release added, which is allowed at whatever it finds until the app
     * records it. A test holds the list to the rules the code actually raises.
     */
    public const RULES = [
        '--ds-prose used as a length',
        'app colour variable',
        'brand colour scale',
        'colour off the roles',
        'Flux badge',
        'Flux heading at section size',
        'Flux colour off the roles',
        'Flux Pro component',
        'Flux table styled on the screen',
        'hardcoded colour',
        'Lucide icon name',
        'page title not in the page header',
        'spacing off the scale',
        'tap target under 36px',
        'text under 12px',
        'weight off the scale',
    ];

    /** Spacing steps that are not on the standard's eight-step scale (05). */
    private const OFF_SCALE = ['5', '7', '9', '10', '11', '13', '14', '15', '18', '20'];

    private const SPACING_PREFIXES = ['p', 'px', 'py', 'pt', 'pb', 'ps', 'pe', 'm', 'mx', 'my', 'mt', 'mb', 'ms', 'me', 'gap', 'gap-x', 'gap-y', 'space-x', 'space-y'];

    /** Colour families the standard does not use directly in a view. */
    private const OFF_PALETTE = ['red', 'amber', 'green', 'blue', 'sky', 'emerald', 'rose', 'orange', 'yellow', 'indigo', 'violet', 'purple', 'pink', 'teal', 'cyan', 'lime', 'stone', 'slate', 'gray', 'neutral'];

    /** A whole file that is deliberately outside the standard says so once, with a reason. */
    /**
     * Flux Pro components and the free one 06 names in each one's place. The
     * fleet does not install Pro, so a screen using one of these renders
     * nothing on a fresh clone and stops Composer at a licence prompt.
     */
    public const FLUX_PRO = [
        'accordion' => 'Stacked <details> sections, each with a heading, one pixel border between them.',
        'autocomplete' => 'flux:select.',
        'calendar' => 'flux:input type="date".',
        'carousel' => 'Nothing: 06 has no carousel.',
        'chart' => 'Nothing yet: 06 has no chart. Show the numbers in a table or stat tiles, and ask for a rule.',
        'color-picker' => 'flux:input type="color".',
        'command' => 'Nothing: navigation is the sidebar\'s.',
        'composer' => 'flux:textarea with a ghost attach button and a primary send.',
        'context' => 'flux:dropdown on a ghost icon button.',
        'date-picker' => 'flux:input type="date".',
        'editor' => 'flux:textarea.',
        'file-item' => 'A line of text under the file input.',
        'file-upload' => 'flux:input type="file".',
        'kanban' => 'Nothing: 06 has no kanban.',
        'pillbox' => 'flux:checkbox.group for many-of-many, flux:select for one.',
        'popover' => 'flux:dropdown, or flux:tooltip for one phrase.',
        'slider' => 'flux:input type="number".',
        'tab' => 'A navlist beside the panel, or a segmented flux:radio.group for two or three options.',
        'tabs' => 'A navlist beside the panel, or a segmented flux:radio.group for two or three options.',
        'time-picker' => 'flux:input type="time".',
        'timeline' => 'A list: events in text, times in text-muted tabular, a border line.',
    ];

    public const IGNORE_FILE = '@ds-audit-ignore-file';

    /** A page a signed-out person reaches, which keeps its own brand (13) and the audit skips. */
    public const PUBLIC_FILE = '@ds-public';

    /**
     * @param  string|null  $path  Where the file lives, when known. A sign-in view
     *                             keeps its own heading, so the page-title rule
     *                             does not apply there.
     * @return list<array{rule: string, line: int, snippet: string, fix: string}>
     */
    public static function file(string $contents, ?string $path = null): array
    {
        // 13: a public page keeps its own brand. The standard unifies the
        // signed-in back end, and a page a signed-out person reaches is not
        // part of that, so it says @ds-public and the audit leaves it alone.
        if (self::optsOut($contents) || self::isPublicView($contents, $path)) {
            return [];
        }

        $findings = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        // Sign-in is the door to the back end, so it is on the roles and the
        // scale, but its heading is not a page title.
        $noPageHeader = $path !== null && self::isAuthView($path);

        foreach ($lines as $i => $line) {
            $n = $i + 1;

            if (str_contains($line, '@ds-audit-ignore')) {
                continue;
            }

            foreach (self::matches($line, '/\b(?:bg|text|border|ring|from|to|via|divide|outline|decoration|shadow|accent|fill|stroke)-('.implode('|', self::OFF_PALETTE).')-\d{2,3}\b/') as $m) {
                $findings[] = self::finding('colour off the roles', $n, $m, 'Use a --ds-* role: text, text-muted, border, accent-content, or a status role.');
            }

            // An accent scale the app defines for itself, accent-50 to
            // accent-950, reads as the style's accent and is not: the style
            // has one accent and its hover, soft and content roles, no steps.
            // Found on team, whose every button and focus ring was its own
            // teal through classes the palette rule took for the style's.
            foreach (self::matches($line, '/\b(?:bg|text|border|ring|from|to|via|divide|outline|decoration|shadow|fill|stroke|placeholder|caret)-accent-\d{2,3}\b|--color-accent-\d{2,3}\b/') as $m) {
                $findings[] = self::finding('brand colour scale', $n, $m, 'Use the accent roles: --ds-accent for a fill, --ds-accent-hover on hover, --ds-accent-soft for a tint, --ds-accent-content for text and icons, --ds-on-accent on a fill.');
            }

            // A colour variable the app defines for itself sits outside the
            // roles the same way a palette class does, and the palette rule
            // cannot see it. Found when a move onto a style dropped an app's
            // own --color-surface and 32 cards lost their background without a
            // single finding. Flux's accent names and the zinc steps are the
            // style's, and dc-* is the alias set the frame keeps.
            foreach (self::matches($line, '/(?:\(|var\()--color-(?!zinc-\d|accent\b|accent-content\b|accent-foreground\b|dc-)[a-z0-9-]+/') as $m) {
                $findings[] = self::finding('app colour variable', $n, substr($m, (int) strpos($m, '--')), 'Use a --ds-* role. A colour the app defines for itself does not follow the style, and nothing reports it when the definition goes.');
            }

            foreach (self::matches($line, '/(?<![\w-])#[0-9a-fA-F]{3,8}\b/') as $m) {
                if (! str_contains($line, '--ds-') && ! str_contains($line, 'dashcore-design')) {
                    $findings[] = self::finding('hardcoded colour', $n, $m, 'Move it to a --ds-* role, or to the design file if it is an identity colour.');
                }
            }

            foreach (self::matches($line, '/\b(?:'.implode('|', self::SPACING_PREFIXES).')-(?:'.implode('|', self::OFF_SCALE).')\b/') as $m) {
                $findings[] = self::finding('spacing off the scale', $n, $m, 'The scale is 1 2 3 4 6 8 12 16. Pick the nearest, or a --ds-* density value.');
            }

            // --ds-prose is the prose type size from 05, not a column width, and
            // the name invites the mistake. As a width it gives a 14px column,
            // which is one word per line and obvious on screen but easy to write.
            foreach (self::matches($line, '/\b(?:max-w|min-w|w|max-h|min-h|h)-\(--ds-prose\)/') as $m) {
                $findings[] = self::finding('--ds-prose used as a length', $n, $m, 'It is a type size. The prose line cap in 03 is 65 characters: max-w-prose.');
            }

            foreach (self::matches($line, '/\bfont-(?:bold|extrabold|black|light|thin|extralight)\b/') as $m) {
                $findings[] = self::finding('weight off the scale', $n, $m, 'Three weights: font-normal, font-medium, font-semibold.');
            }

            foreach (self::matches($line, '/\btext-\[(?:[0-9]|10|11)px\]/') as $m) {
                $findings[] = self::finding('text under 12px', $n, $m, 'Nothing is set below 12px, including badges and table heads.');
            }

            // 05: a tap target is never under 36px, which is the compact control
            // height. Only the interactive element's own class attribute counts.
            // An icon inside a button is allowed to be 20px, and matching the
            // whole line would report every icon button in the app.
            foreach (self::tapTargets($line) as $m) {
                $findings[] = self::finding('tap target under 36px', $n, $m, '05 sets the control height at 40px, or 36px compact, and nothing interactive goes under it. Use h-9 or the --ds-control value.');
            }

            // 03 and 06: flux:heading size="lg" is 16px at weight 500 where the
            // section heading role is 18px at 600. size="xl" is caught by the
            // page-title rule below, and the default size is the UI emphasis
            // role, which Flux gets right.
            foreach (self::matches($line, '/<flux:heading[^>]*size="lg"/') as $m) {
                $findings[] = self::finding('Flux heading at section size', $n, $m, 'Use x-ds::section-heading: 18px at 600. Flux renders size="lg" as 16px at 500.');
            }

            // 06 is in the applied CSS block for the table: head on sunken at
            // 12px muted, body at 14px on the density row height, first column
            // strong. A screen that writes any of it again reaches into Flux's
            // internals to say what the adapter already says, and then stops
            // matching the moment the adapter moves. Found on a real screen
            // whose own comment said it belonged in the adapter.
            foreach (self::matches($line, '/\[data-flux-(?:table|column|cell|row)\]/') as $m) {
                $findings[] = self::finding('Flux table styled on the screen', $n, $m, "06's table styling is in the applied CSS block. Use a plain flux:table and let the adapter do it.");
            }

            // 06: the fleet is on free Flux. Matched on the tag name and what
            // may follow it, so flux:table is not taken for flux:tab.
            foreach (self::matches($line, '/<flux:(?:'.implode('|', array_map(fn (string $tag): string => preg_quote($tag, '/'), array_keys(self::FLUX_PRO))).')(?=[\s>.\/])/') as $m) {
                $findings[] = self::finding('Flux Pro component', $n, $m, 'The fleet is on free Flux. Use: '.self::FLUX_PRO[explode('.', substr($m, 6))[0]]);
            }

            foreach (self::matches($line, '/<flux:select\b[^>]*variant="(?:listbox|combobox)"/') as $m) {
                $findings[] = self::finding('Flux Pro component', $n, $m, 'The listbox and combobox variants are Pro. Use a plain flux:select.');
            }

            foreach (self::matches($line, '/<flux:badge\b/') as $m) {
                $findings[] = self::finding('Flux badge', $n, $m, 'Use x-ds::badge: a status for the four the standard names, no status for a category, a role or a plan. Flux paints badges from Tailwind, not the standard roles.');
            }

            foreach (self::fluxColours($line) as [$m, $fix]) {
                $findings[] = self::finding('Flux colour off the roles', $n, $m, $fix);
            }

            foreach (self::matches($line, '/\b(?:'.implode('|', array_keys(Applier::LUCIDE_RENAMES)).')\b/') as $m) {
                $findings[] = self::finding('Lucide icon name', $n, $m, 'Heroicons only. `design:apply` renames these.');
            }

            if (! $noPageHeader) {
                foreach (self::matches($line, '/<flux:heading[^>]*size="xl"|<h1\b/') as $m) {
                    $findings[] = self::finding('page title not in the page header', $n, $m, 'Titles go through x-ds::page-header, which applies the pairing second face.');
                }
            }
        }

        return $findings;
    }

    /**
     * A Flux component given a colour the style cannot turn into a role. Flux
     * paints color= straight from Tailwind's palette. The style corrects a
     * callout or a progress bar in a status family to that status, and zinc
     * is the style's own neutral ramp; anything else is Tailwind on the
     * screen. A bound colour counts by the literals in its expression, and
     * one with none, a variable, cannot be judged here and is reported.
     * Badges have their own rule.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fluxColours(string $line): array
    {
        if (preg_match_all('/<flux:([a-z][\w.-]*)\b[^>]*?\s(:?)color="([^"]*)"/', $line, $all, PREG_SET_ORDER) === 0) {
            return [];
        }

        $status = array_merge(...array_values(Applier::CALLOUT_FAMILIES));
        $found = [];

        foreach ($all as [$m, $tag, $bound, $value]) {
            if ($tag === 'badge') {
                continue;
            }

            $corrected = in_array($tag, ['callout', 'progress'], true);
            $allowed = $corrected ? [...$status, 'zinc'] : ['zinc'];
            $colours = $bound === ':' && preg_match_all("/'([a-z]+)'/", $value, $literals) > 0 ? $literals[1] : ($bound === ':' ? [] : [$value]);

            if ($colours !== [] && array_diff($colours, $allowed) === []) {
                continue;
            }

            $found[] = ['<flux:'.$tag.' '.$bound.'color="'.$value.'"', match (true) {
                $tag === 'button' => 'Colour on a button is variant="danger" for a destructive action or variant="primary" for the main one. color= paints Tailwind\'s palette.',
                $corrected => 'Use a status family (red, amber, green, blue), which the style turns into its status role, or no colour for the accent. Anything else is Tailwind on the screen.',
                default => 'Drop color=. Colour on the back end means status, and Flux paints this one from Tailwind rather than the roles.',
            }];
        }

        return $found;
    }

    /** True when the file carries the whole-file opt-out marker. */
    public static function optsOut(string $contents): bool
    {
        return str_contains($contents, self::IGNORE_FILE);
    }

    /**
     * A public page, which keeps its own brand (13). It says so with a marker
     * rather than by where it lives, because "public" is a decision about who
     * reaches a page and no path spells that reliably. Laravel's own
     * welcome.blade.php is the exception: every app has one and it is always
     * the landing page.
     */
    public static function isPublicView(string $contents, ?string $path = null): bool
    {
        if (str_contains($contents, self::PUBLIC_FILE)) {
            return true;
        }

        return $path !== null && str_ends_with(str_replace('\\', '/', $path), '/welcome.blade.php');
    }

    /**
     * Sign-in, register, reset, and the layouts under them: a form on a page
     * of its own, not an app screen, so a heading there is not a page title.
     */
    public static function isAuthView(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return (bool) preg_match('~(^|/)auth(/|\.blade\.php$|-[^/]*\.blade\.php$)~', $path);
    }

    /**
     * Interactive elements sized under 36px by their own class attribute.
     *
     * Tailwind's h-9 is 36px, so h-1 through h-8 are under it, as is any
     * arbitrary value below 36. A Flux button at size="xs" is about 24px and
     * counts too.
     *
     * @return list<string>
     */
    private static function tapTargets(string $line): array
    {
        $found = [];

        if (preg_match_all('/<(?:button|a|input|select|textarea)\b[^>]*?\bclass="([^"]*)"/', $line, $tags)) {
            foreach ($tags[1] as $classes) {
                // The boundary goes after the number only. An arbitrary value
                // ends in "]", and \b never matches between "]" and a quote.
                foreach (self::matches($classes, '/\b(?:h|size|min-h)-(?:[1-8]\b|\[(?:[0-9]|[12][0-9]|3[0-5])px\])/') as $m) {
                    $found[] = $m;
                }
            }
        }

        // Flux button heights: base is h-10 at 40px, sm is h-8 at 32px, xs is
        // h-6 at 24px. Only base clears the floor in 05, so both smaller sizes
        // are findings. The rule used to name xs alone, which was inconsistent
        // with its own first branch: a hand-written h-8 button was already
        // reported, while the Flux size that renders as h-8 was not.
        foreach (self::matches($line, '/<flux:button\b[^>]*?\bsize="(?:xs|sm)"/') as $m) {
            $found[] = $m;
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private static function matches(string $line, string $pattern): array
    {
        preg_match_all($pattern, $line, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return array{rule: string, line: int, snippet: string, fix: string} */
    private static function finding(string $rule, int $line, string $snippet, string $fix): array
    {
        return ['rule' => $rule, 'line' => $line, 'snippet' => $snippet, 'fix' => $fix];
    }

    /**
     * The mechanical half: rewrites with no judgment in them. Returns the new
     * contents and what changed. Anything needing a look at the screen is left
     * for the report.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function mechanical(string $contents): array
    {
        $changed = [];

        $weights = ['font-bold' => 'font-semibold', 'font-extrabold' => 'font-semibold', 'font-black' => 'font-semibold', 'font-light' => 'font-normal', 'font-thin' => 'font-normal', 'font-extralight' => 'font-normal'];
        foreach ($weights as $from => $to) {
            $out = preg_replace('/\b'.preg_quote($from, '/').'\b/', $to, $contents, -1, $count);
            if ($count > 0) {
                $contents = (string) $out;
                $changed[] = "{$from} to {$to} ({$count})";
            }
        }

        // Off-scale spacing to the nearest step on the eight-step scale.
        $steps = ['5' => '4', '7' => '6', '9' => '8', '10' => '8', '11' => '12', '13' => '12', '14' => '12', '15' => '16', '18' => '16', '20' => '16'];
        foreach ($steps as $from => $to) {
            $pattern = '/\b((?:'.implode('|', self::SPACING_PREFIXES).')-)'.$from.'\b/';
            $out = preg_replace($pattern, '${1}'.$to, $contents, -1, $count);
            if ($count > 0) {
                $contents = (string) $out;
                $changed[] = "spacing {$from} to {$to} ({$count})";
            }
        }

        return [$contents, $changed];
    }
}
