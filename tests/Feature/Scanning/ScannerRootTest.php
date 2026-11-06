<?php

namespace Tests\Feature\Scanning;

use App\Scanning\CheckoutLocator;
use App\Scanning\ScannerRoot;
use Tests\TestCase;

/**
 * SCANNER_ROOT reaches tests through its launchd environment; when it was
 * lost every app's local_path went null and the nightly queued nothing
 * (REQ-476, REQ-605). On every install the root now falls back to the folder
 * tests' own checkout sits in, beside every other app's.
 */
class ScannerRootTest extends TestCase
{
    public function test_an_unset_or_empty_root_is_the_parent_of_tests_own_checkout(): void
    {
        foreach ([null, '', '  '] as $unset) {
            config(['scanner.root' => $unset, 'scanner.root_fallback' => true]);

            ScannerRoot::apply();

            $this->assertSame(dirname(base_path()), config('scanner.root'), var_export($unset, true));
        }

        // Every checkout under it is found — tests' own among them.
        $locator = app(CheckoutLocator::class);
        $this->assertSame(realpath(base_path()), $locator->find(basename(base_path())));
    }

    public function test_every_install_falls_back_not_only_the_home_one(): void
    {
        config(['scanner.root' => null, 'scanner.root_fallback' => true, 'install.fleet' => 'acme']);

        ScannerRoot::apply();

        $this->assertSame(dirname(base_path()), config('scanner.root'));
        $this->assertSame(dirname(base_path()), app(CheckoutLocator::class)->root());
    }

    public function test_a_set_root_still_wins(): void
    {
        $root = sys_get_temp_dir();
        config(['scanner.root' => $root, 'scanner.root_fallback' => true]);

        ScannerRoot::apply();

        $this->assertSame($root, config('scanner.root'));
    }

    public function test_the_fallback_can_be_switched_off(): void
    {
        config(['scanner.root' => null, 'scanner.root_fallback' => false]);

        ScannerRoot::apply();

        $this->assertNull(config('scanner.root'));
        $this->assertNull(config('simulation.targets.sales.checkout'));
    }

    public function test_proving_ground_target_checkouts_follow_the_resolved_root(): void
    {
        config([
            'scanner.root' => null,
            'scanner.root_fallback' => true,
            'simulation.targets.sales.checkout' => null,
            'simulation.targets.finance.checkout' => '/elsewhere/finance.fakecompany.com',
        ]);

        ScannerRoot::apply();

        $this->assertSame(dirname(base_path()).'/sales.fakecompany.com', config('simulation.targets.sales.checkout'));
        $this->assertSame(dirname(base_path()).'/marketing.fakecompany.com', config('simulation.targets.marketing.checkout'));
        // A checkout set of its own still wins.
        $this->assertSame('/elsewhere/finance.fakecompany.com', config('simulation.targets.finance.checkout'));
    }

    public function test_a_set_root_fills_target_checkouts_too(): void
    {
        $root = sys_get_temp_dir();
        config(['scanner.root' => $root, 'simulation.targets.hr.checkout' => null]);

        ScannerRoot::apply();

        $this->assertSame(rtrim($root, '/').'/hr.fakecompany.com', config('simulation.targets.hr.checkout'));
    }
}
