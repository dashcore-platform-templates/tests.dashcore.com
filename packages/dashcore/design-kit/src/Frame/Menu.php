<?php

declare(strict_types=1);

namespace Dashcore\DesignKit\Frame;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Turns an app's menu array into the one shape the sidebar renders.
 *
 * An app owns its menu — what is in it, what it is called, where it goes.
 * The package owns how it looks. This is the seam between the two, so it
 * accepts the forms apps already write rather than making each one convert:
 *
 *     ['label' => 'Overview', 'route' => 'dashboard']
 *     ['label' => 'Docs', 'href' => '/help']
 *     ['label' => 'Inbox', 'route' => 'inbox', 'badge' => 3]                 // a count beside it
 *     ['label' => 'ops@', 'href' => '/m/1', 'hint' => 'Purpose not set', 'tone' => 'warning']
 *     ['label' => 'More', 'collapsed' => true, 'items' => [...]]            // folds; opens on its own pages
 *     ['label' => 'Mailboxes', 'link' => ['label' => 'Manage', 'route' => 'mailboxes'], 'items' => [...]]
 *     ['label' => 'Fleet', 'items' => ['admin.plan' => 'Plan', ...]]        // route => label
 *     ['label' => 'Fleet', 'items' => [['label' => 'Plan', 'route' => ...]]]
 *
 * An entry whose named route does not exist is dropped rather than thrown on:
 * a menu shared across environments should not 500 because one feature is
 * switched off in this one.
 */
class Menu
{
    /**
     * The menu as sections, in order. A heading with its entries is one
     * section; consecutive top-level entries are gathered into one unlabelled
     * section, so three links in a row read as a list rather than as three
     * sections each with a gap above it.
     *
     * @param  array<int, array<string, mixed>>  $menu
     * @return list<array{label: ?string, items: list<array<string, mixed>>, collapsible: bool, open: bool, link: ?array{label: string, href: string}}>
     */
    public static function resolve(array $menu): array
    {
        $sections = [];
        $loose = [];

        $flush = function () use (&$sections, &$loose) {
            if ($loose !== []) {
                $sections[] = ['label' => null, 'items' => $loose, 'collapsible' => false, 'open' => true, 'link' => null];
                $loose = [];
            }
        };

        foreach ($menu as $entry) {
            if (! isset($entry['items'])) {
                if (($link = self::link($entry)) !== null) {
                    $loose[] = $link;
                }

                continue;
            }

            $flush();

            $items = [];

            foreach ($entry['items'] as $key => $item) {
                $item = is_string($item) ? ['label' => $item, 'route' => $key] : $item;

                if (($link = self::link($item)) !== null) {
                    $items[] = $link;
                }
            }

            if ($items === []) {
                continue;
            }

            $current = in_array(true, array_column($items, 'active'), true);

            $sections[] = [
                'label' => (string) $entry['label'],
                'items' => $items,
                // A long menu can fold its less-used sections away. A folded
                // section opens by itself when the page is inside it — a menu
                // that hides where you are is worse than a long one.
                'collapsible' => (bool) ($entry['collapsed'] ?? false),
                'open' => ! ($entry['collapsed'] ?? false) || $current,
                // One quiet link beside the heading — "Manage" beside a list of
                // mailboxes — for the action about the section as a whole.
                'link' => isset($entry['link']) ? self::headingLink($entry['link']) : null,
            ];
        }

        $flush();

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array{label: string, href: string}|null
     */
    private static function headingLink(array $link): ?array
    {
        if (isset($link['route']) && ! Route::has($link['route'])) {
            return null;
        }

        return [
            'label' => (string) $link['label'],
            'href' => isset($link['route']) ? route($link['route'], $link['params'] ?? []) : (string) ($link['href'] ?? '#'),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, href: string, active: bool, icon: ?string, badge: ?string, hint: ?string, tone: ?string}|null
     */
    private static function link(array $item): ?array
    {
        if (isset($item['route'])) {
            if (! Route::has($item['route'])) {
                return null;
            }

            $href = route($item['route'], $item['params'] ?? []);
        } else {
            $href = (string) ($item['href'] ?? '#');
        }

        return [
            'label' => (string) $item['label'],
            'href' => $href,
            'active' => (bool) ($item['active'] ?? self::isActive($item, $href)),
            'icon' => $item['icon'] ?? null,
            // A count beside the label. Zero and null render nothing: an entry
            // showing "0" is a badge asking for attention it does not need.
            'badge' => filled($item['badge'] ?? null) && ($item['badge'] ?? null) !== 0 ? (string) $item['badge'] : null,
            // A second, muted line under the label — a mailbox's purpose — and
            // an optional tone for it when it is a problem to fix rather than
            // a description. Only the hint takes the tone; the label stays ink.
            'hint' => filled($item['hint'] ?? null) ? (string) $item['hint'] : null,
            'tone' => in_array($item['tone'] ?? null, ['success', 'warning', 'danger'], true) ? $item['tone'] : null,
        ];
    }

    /**
     * Whether the current request falls under this entry.
     *
     * By route-name prefix when the entry names a route, so a show page lights
     * its own index; by path prefix otherwise. `match` overrides both with a
     * route pattern (or list of them) for the entry that fronts a section
     * whose routes are not named after it.
     *
     * @param  array<string, mixed>  $item
     */
    private static function isActive(array $item, string $href): bool
    {
        $request = request();

        if (isset($item['match'])) {
            return $request->routeIs(...(array) $item['match']);
        }

        if (isset($item['route'])) {
            return $request->routeIs(Str::before($item['route'], '.index').'*');
        }

        $path = trim((string) parse_url($href, PHP_URL_PATH), '/');

        // The root would prefix-match every page in the app, so it only
        // lights for itself.
        return $path === ''
            ? $request->path() === '/'
            : $request->is($path, $path.'/*');
    }
}
