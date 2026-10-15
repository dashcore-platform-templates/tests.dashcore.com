<?php

namespace Dashcore\Bridge\Fleet;

use InvalidArgumentException;

/**
 * Every application a fleet can have, written down once.
 *
 * Until this lived here each consumer kept its own list — api's `FleetPlan`,
 * ceo's `FleetMap`, standards' targets, the scheduler script in _fleet-ops —
 * and each drifted from the rest on its own schedule. Every fleet app
 * already requires this package, so this is the one copy they can all read.
 *
 * The catalogue is not an install. It knows what an app is called, where it
 * sits under a domain and which repository deploys it; it does not know which
 * domain, which apps a given company has, or which environment it is running
 * in. Those belong to the install and are passed in, so nothing here reads
 * config or env.
 *
 * Grants are deliberately not here. Who may call whom is the hub's decision,
 * it changes weekly, and it is applied by the hub alone — shipping it through
 * a package release would slow every grant change down for no reader's
 * benefit.
 */
final class Catalogue
{
    /** The control plane's key. Every install has it, listed or not. */
    public const CONTROL_PLANE = 'api';

    /**
     * The apps every install has, listed or not: the control plane, the
     * fleet's own sign-in, the auditor that holds it to the standard, the
     * tests that prove it works, and the design that sets the look of every
     * app in it. A client fleet without login comes up with nobody able to
     * sign in, and one without design has no way to pick its visual design,
     * even when it starts with ceo alone — so these are never left to the
     * order form.
     *
     * tests and design were dropped in 0.11.24 because neither could yet be
     * a peer: design carried no bridge and neither had a release in
     * Templates. Both carry the bridge and are released now, so they are
     * back.
     *
     * memory joined in 0.11.33: it is where a fleet's memory is kept — what
     * its sessions learn, what the team knows, the plan — and a fleet that
     * learns has to have somewhere to put it from its first day.
     *
     * onboarding joined in 0.11.34: it is how a fleet gets its set-up and
     * the client's company data, in one session on a call, so no fleet
     * opens empty.
     *
     * @var list<string>
     */
    public const REQUIRED = [self::CONTROL_PLANE, 'login', 'standards', 'tests', 'design', 'memory', 'onboarding'];

    /**
     * Keyed by short name. A null subdomain is the domain's apex — the
     * company's own website. `repo` is the GitHub repository the app is
     * developed in, named for its Dashcore hostname. A client install
     * deploys the mirror of the same name in dashcore-fleet, not this
     * repository: the builder swaps the owner, so the name is all it needs.
     *
     * @var array<string, array{subdomain: ?string, group: string, repo: string}>
     */
    public const APPS = [
        'api' => ['subdomain' => 'api', 'group' => 'Control plane', 'repo' => 'dashcoretech/api.dashcore.com'],
        'brain' => ['subdomain' => 'brain', 'group' => 'Agent platform', 'repo' => 'dashcoretech/brain.dashcore.com'],
        'agents' => ['subdomain' => 'agents', 'group' => 'Agent platform', 'repo' => 'dashcoretech/agents.dashcore.com'],
        'memory' => ['subdomain' => 'memory', 'group' => 'Agent platform', 'repo' => 'dashcoretech/memory.dashcore.com'],
        'finance' => ['subdomain' => 'finance', 'group' => 'Domain service', 'repo' => 'dashcoretech/finance.dashcore.com'],
        'hr' => ['subdomain' => 'hr', 'group' => 'Domain service', 'repo' => 'dashcoretech/hr.dashcore.com'],
        'email' => ['subdomain' => 'email', 'group' => 'Domain service', 'repo' => 'dashcoretech/email.dashcore.com'],
        'sales' => ['subdomain' => 'sales', 'group' => 'Domain service', 'repo' => 'dashcoretech/sales.dashcore.com'],
        'marketing' => ['subdomain' => 'marketing', 'group' => 'Domain service', 'repo' => 'dashcoretech/marketing.dashcore.com'],
        'health' => ['subdomain' => 'health', 'group' => 'Domain service', 'repo' => 'dashcoretech/health.dashcore.com'],
        'talent' => ['subdomain' => 'talent', 'group' => 'Domain service', 'repo' => 'dashcoretech/talent.dashcore.com'],
        'coaching' => ['subdomain' => 'coaching', 'group' => 'Domain service', 'repo' => 'dashcoretech/coaching.dashcore.com'],
        'delivery' => ['subdomain' => 'dcos', 'group' => 'Delivery', 'repo' => 'dashcoretech/dcos.dashcore.com'],
        'ceo' => ['subdomain' => 'ceo', 'group' => 'Surface', 'repo' => 'dashcoretech/ceo.dashcore.com'],
        'website' => ['subdomain' => null, 'group' => 'Surface', 'repo' => 'dashcoretech/dashcore.com'],
        'team' => ['subdomain' => 'team', 'group' => 'Surface', 'repo' => 'dashcoretech/team.dashcore.com'],
        'audit' => ['subdomain' => 'audit', 'group' => 'Delivery', 'repo' => 'dashcoretech/audit.dashcore.com'],
        'onboarding' => ['subdomain' => 'onboarding', 'group' => 'Delivery', 'repo' => 'dashcoretech/onboarding.dashcore.com'],
        'security' => ['subdomain' => 'security', 'group' => 'Assurance', 'repo' => 'dashcoretech/security.dashcore.com'],
        'standards' => ['subdomain' => 'standards', 'group' => 'Assurance', 'repo' => 'dashcoretech/standards.dashcore.com'],
        'tests' => ['subdomain' => 'tests', 'group' => 'Assurance', 'repo' => 'dashcoretech/tests.dashcore.com'],
        'design' => ['subdomain' => 'design', 'group' => 'Surface', 'repo' => 'dashcoretech/design.dashcore.com'],
        'login' => ['subdomain' => 'login', 'group' => 'Identity', 'repo' => 'dashcoretech/login.dashcore.com'],
        'services' => ['subdomain' => 'services', 'group' => 'Domain service', 'repo' => 'dashcoretech/services.dashcore.com'],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::APPS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::APPS);
    }

    /**
     * @return array{subdomain: ?string, group: string, repo: string}
     */
    public static function app(string $key): array
    {
        return self::APPS[$key] ?? throw self::unknown([$key]);
    }

    /**
     * An app's production hostname under the given domain.
     */
    public static function host(string $key, string $domain): string
    {
        $subdomain = self::app($key)['subdomain'];
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        return $subdomain === null ? $domain : $subdomain.'.'.$domain;
    }

    /**
     * The apps an install has, in catalogue order, from the list it declares
     * (`FLEET_APPS`, as an array or a comma-separated string).
     *
     * An empty list is the whole catalogue — Dashcore's own install. The
     * required apps are always present: an install without its hub, its
     * sign-in or its assurance is not an install. A name the catalogue does not hold throws rather than being
     * skipped, because a typo that quietly drops an app reads, on every page,
     * exactly like an app nobody bought.
     *
     * @param  list<string>|string|null  $listed
     * @return list<string>
     */
    public static function installed(array|string|null $listed): array
    {
        $listed = self::normalise($listed);

        if ($unknown = array_values(array_diff($listed, self::keys()))) {
            throw self::unknown($unknown);
        }

        return array_values(array_filter(
            self::keys(),
            fn (string $key) => $listed === [] || in_array($key, self::REQUIRED, true) || in_array($key, $listed, true),
        ));
    }

    /**
     * @param  list<string>|string|null  $listed
     * @return list<string>
     */
    private static function normalise(array|string|null $listed): array
    {
        $items = is_array($listed) ? $listed : explode(',', (string) $listed);

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $key) => strtolower(trim((string) $key)),
            $items,
        ))));
    }

    /**
     * @param  list<string>  $keys
     */
    private static function unknown(array $keys): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'The fleet catalogue does not hold: '.implode(', ', $keys)
            .'. Known: '.implode(', ', self::keys()).'.'
        );
    }
}
