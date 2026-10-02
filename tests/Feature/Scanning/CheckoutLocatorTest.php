<?php

namespace Tests\Feature\Scanning;

use App\Scanning\CheckoutLocator;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class CheckoutLocatorTest extends TestCase
{
    use UsesFleetFixtures;

    public function test_a_checkout_is_found_by_host_under_the_root(): void
    {
        $root = $this->scannerRootWith(['finance.dashcore.com']);

        $this->assertSame($root.'/finance.dashcore.com', app(CheckoutLocator::class)->find('finance.dashcore.com'));
        $this->assertNull(app(CheckoutLocator::class)->find('hr.dashcore.com'));
    }

    public function test_a_directory_without_artisan_is_not_an_app(): void
    {
        $root = $this->scannerRootWith([]);
        mkdir($root.'/notes.dashcore.com');

        $this->assertNull(app(CheckoutLocator::class)->find('notes.dashcore.com'));
    }

    public function test_nothing_outside_the_root_can_be_reached(): void
    {
        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $outside = $this->scannerRoot.'-outside';
        mkdir($outside);
        file_put_contents($outside.'/artisan', '<?php');
        symlink($outside, $root.'/evil.dashcore.com');

        try {
            $locator = app(CheckoutLocator::class);

            $this->assertNull($locator->find('evil.dashcore.com'), 'a symlink out of the root is refused');
            $this->assertNull($locator->find('../'.basename($outside)));
            $this->assertNull($locator->find(''));
            $this->assertFalse($locator->contains($outside));
            $this->assertTrue($locator->contains($root.'/finance.dashcore.com'));
            $this->assertFalse($locator->contains($root), 'the root itself is not an app');
        } finally {
            unlink($outside.'/artisan');
            rmdir($outside);
        }
    }

    public function test_with_no_root_configured_nothing_is_scannable(): void
    {
        config(['scanner.root' => null]);

        $this->assertNull(app(CheckoutLocator::class)->root());
        $this->assertNull(app(CheckoutLocator::class)->find('finance.dashcore.com'));
    }
}
