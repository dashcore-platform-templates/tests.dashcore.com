<?php

namespace Tests\Concerns;

use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A fake control plane manifest and a throwaway scanner root of checkouts,
 * so fleet code can be exercised without the hub or the real ~/Herd.
 */
trait UsesFleetFixtures
{
    protected ?string $scannerRoot = null;

    protected function tearDownUsesFleetFixtures(): void
    {
        if ($this->scannerRoot !== null) {
            File::deleteDirectory($this->scannerRoot);
            $this->scannerRoot = null;
        }
    }

    /**
     * @param  array<string, mixed>|\Throwable  $manifest
     */
    protected function fakeManifest(array|\Throwable $manifest): void
    {
        $this->app->instance(KeysetResolver::class, new class($manifest) extends ManifestKeysetResolver
        {
            public function __construct(private readonly array|\Throwable $fake) {}

            public function manifest(): array
            {
                if ($this->fake instanceof \Throwable) {
                    throw $this->fake;
                }

                return $this->fake;
            }
        });
    }

    /**
     * @param  array<string, string>  $apps  key => enrolled-as host
     */
    protected function fakeInstall(array $apps, string $domain = 'dashcore.com'): void
    {
        $this->fakeManifest(['apps' => [], 'grants' => [], 'install' => ['domain' => $domain, 'apps' => $apps]]);
    }

    /**
     * A scanner root holding a checkout per host; each gets an artisan file
     * and, unless $git is false, an initialised git directory.
     */
    protected function scannerRootWith(array $hosts, bool $git = true): string
    {
        $this->scannerRoot ??= realpath(sys_get_temp_dir()).'/scanner-root-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->scannerRoot);

        foreach ($hosts as $host) {
            File::ensureDirectoryExists($this->scannerRoot.'/'.$host);
            file_put_contents($this->scannerRoot.'/'.$host.'/artisan', "<?php\n");

            if ($git) {
                File::ensureDirectoryExists($this->scannerRoot.'/'.$host.'/.git');
            }
        }

        config(['scanner.root' => $this->scannerRoot]);

        return $this->scannerRoot ?? throw new RuntimeException('no root');
    }
}
