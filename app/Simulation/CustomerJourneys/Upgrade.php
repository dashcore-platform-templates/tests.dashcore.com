<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Persona;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use App\Simulation\StandInGuard;
use Faker\Generator;
use RuntimeException;

/**
 * The customer's fleet moves to a newer release, and its owner sees it.
 *
 * A client fleet runs releases from Templates, never Dashcore's `main`, and
 * moves only when fleets pushes one to it (`fleet:push`), which is done once
 * the app is found running that release's commit. The owner learns of it in
 * their cockpit's Settings › System: the release it runs, and "Upgrade
 * available" while Templates holds a newer one.
 *
 * So the journey takes the newest client fleet dcos was told is built (the
 * Provisioning journey's), has fleets put its cockpit on an older release
 * (`simulation.fleets.*.upgrade.from`), and the owner signs in and sees the
 * notice offering the newer one. fleets pushes the newer release
 * (`upgrade.to`); the owner signs in again and sees it running, and the
 * notice gone.
 *
 * The cockpit can only say so if it reads its releases from Templates and
 * may read them there: a fleet built by a fleets without
 * Environment::releases still reads dashcoretech's, and Templates is
 * private, so it needs a read-only token (RELEASE_TOKEN, filed in keys under
 * the cockpit's name). Both are checked once the first push has rewritten
 * its environment, before anybody signs in — and refused, the cockpit is
 * put back on the newer release rather than left behind.
 *
 * The owner signs in with a password set for this run by the cockpit's own
 * `app:make-admin --password` — as a practice fleet's sales rep is made —
 * because the one they chose in First sign-in was never kept.
 */
class Upgrade extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    /** @var array<string, mixed>|null the cockpit, as a fleet entry */
    private ?array $cockpit = null;

    /** @var array{app: string, from: string, to: string} */
    private array $releases = ['app' => 'ceo', 'from' => 'v1.0.0', 'to' => 'v1.1.0'];

    private bool $upgraded = false;

    public function __construct(private Provisioner $provisioner, private StandInGuard $guard) {}

    public function name(): string
    {
        return "The customer's fleet moves to a newer release, and its owner sees the upgrade arrive";
    }

    public function guards(): array
    {
        return ['delivery'];
    }

    public function providesApps(): array
    {
        return ['cockpit'];
    }

    public function signsIn(): array
    {
        return ['cockpit' => 'Owner'];
    }

    public function prepare(array $fleet, callable $say): array
    {
        $this->provisioner->source($fleet);
        $this->releases = [...$this->releases, ...array_filter((array) ($fleet['upgrade'] ?? []))];
        $this->built = $this->provisioner->show($fleet['apps']['delivery']);
        $cockpit = $this->provisioner->cockpit($this->built, (string) $fleet['client_root']);
        $install = (string) ($this->built['install_slug'] ?? '');

        if ($install === '') {
            throw new RuntimeException("dcos does not say what fleets calls {$this->built['company']}'s fleet (install_slug), so there is nothing to push a release to.");
        }

        ['app' => $app, 'from' => $from, 'to' => $to] = $this->releases;
        $say("{$this->built['company']}'s fleet is {$install}; fleets puts its {$app} on {$from} first.");

        if (! $this->provisioner->push((string) $fleet['fleets_checkout'], $install, $app, $from, (int) $fleet['provisioning_timeout'], $say)) {
            throw new RuntimeException("fleets could not put {$install}'s {$app} on {$from}; its output above says why.");
        }

        try {
            $this->refuseUnlessItCanSee($cockpit, (string) ($fleet['releases_owner'] ?? 'dashcore-platform-templates'));
        } catch (RuntimeException $refused) {
            // Not left behind on the older release for nothing.
            $back = $this->provisioner->push((string) $fleet['fleets_checkout'], $install, $app, $to, (int) $fleet['provisioning_timeout'], $say);

            throw new RuntimeException($refused->getMessage().' '.($back
                ? "fleets put it back on {$to}."
                : "It is left on {$from}: `php artisan fleet:push {$install} {$app} {$to}` in fleets puts it back."));
        }

        $say($this->provisioner->checkRelease($cockpit));

        return ['cockpit' => $this->cockpit = [
            ...$cockpit,
            'personas' => [['name' => (string) $this->built['owner_name'], 'email' => (string) $this->built['owner_email'], 'role' => 'Owner']],
            // Their account exists; this only gives it a password for this run.
            'persona_command' => ['app:make-admin', '{email}', '--password={password}'],
        ]];
    }

    public function script(array $people, Generator $faker): Script
    {
        $owner = $people['cockpit'] ?? new Persona('The owner', 'owner@example.com', '', 'Owner');
        ['from' => $from, 'to' => $to] = $this->versions();

        return $this->settings((new Script)->on('cockpit'), $owner)
            ->see($from, "It says it is running {$from}")
            ->see('Upgrade available', 'and that an upgrade is available')
            ->see($to, "naming {$to}")
            ->summary("{$owner->firstName()} saw {$from} offered an upgrade to {$to}");
    }

    public function next(array $fleet, array $people, Generator $faker, callable $say): ?Script
    {
        if ($this->upgraded) {
            return null;
        }

        ['app' => $app, 'to' => $release] = $this->releases;
        $install = (string) $this->built['install_slug'];
        $say("fleets pushes {$release} to {$install}'s {$app}.");

        if (! $this->provisioner->push((string) $fleet['fleets_checkout'], $install, $app, $release, (int) $fleet['provisioning_timeout'], $say)) {
            throw new RuntimeException("fleets could not push {$release} to {$install}'s {$app}; its output above says why.");
        }

        $say($this->provisioner->checkRelease($this->cockpit));
        $this->upgraded = true;

        $owner = $people['cockpit'];
        ['from' => $from, 'to' => $to] = $this->versions();

        return $this->settings((new Script)->on('cockpit'), $owner)
            ->see($to, "It says it is running {$to}")
            ->see('Up to date', 'and that it is up to date')
            ->gone('.eos-tag-warning', 'The upgrade notice is gone')
            ->summary("{$this->built['company']}'s cockpit moved from {$from} to {$to} through fleet:push, and {$owner->firstName()} saw the upgrade offered, then arrive");
    }

    /** The owner signs in and opens Settings › System, where the release is shown. */
    private function settings(Script $script, Persona $owner): Script
    {
        return $this->signIn($script, $owner, ['submit' => 'form button[type="submit"]', 'landed' => ", {$owner->firstName()}"])
            ->visit('/settings/system', "{$owner->firstName()} opens Settings › System");
    }

    /**
     * Versions as the cockpit prints them, `1.1.0` for the tag `v1.1.0`.
     *
     * @return array{from: string, to: string}
     */
    private function versions(): array
    {
        return [
            'from' => ltrim($this->releases['from'], 'vV'),
            'to' => ltrim($this->releases['to'], 'vV'),
        ];
    }

    /**
     * Refuse, before anyone signs in, a cockpit that cannot know of a newer
     * release: one still pointed at a repository it does not deploy from,
     * or with no token that may read Templates.
     *
     * @param  array<string, mixed>  $cockpit
     */
    private function refuseUnlessItCanSee(array $cockpit, string $owner): void
    {
        $repository = "{$owner}/ceo.dashcore.com";

        if (($refusal = $this->guard->settingRefusal((string) $cockpit['checkout'], ['release.repository' => $repository])) !== null) {
            throw new RuntimeException("{$refusal} The fleets that built it does not tell a client's apps where their releases are (Environment::releases).");
        }

        if ($this->guard->startsWith((string) $cockpit['checkout'], 'release.token', 'github_pat_') !== true) {
            throw new RuntimeException(sprintf(
                '%s cannot read %s: Templates is private and it holds no read-only token. File a fine-grained token (Contents: read on %s) in keys as %s__RELEASE_TOKEN__local, and fleets hands it over on the next push.',
                basename((string) $cockpit['checkout']),
                $repository,
                $repository,
                basename((string) $cockpit['checkout']),
            ));
        }
    }
}
