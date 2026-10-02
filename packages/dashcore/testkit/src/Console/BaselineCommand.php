<?php

namespace Dashcore\Testkit\Console;

use Dashcore\Testkit\Checks\LoadingFeedback;
use Dashcore\Testkit\Checks\RouteGuards;
use Dashcore\Testkit\Checks\SourceCalls;
use Illuminate\Console\Command;

/**
 * Records what the app has today as its allowed exceptions, so the baseline
 * test passes now and fails on anything new. Run at install; run again only
 * on purpose — re-baselining hides whatever crept in since.
 */
class BaselineCommand extends Command
{
    protected $signature = 'testkit:baseline';

    protected $description = 'Record the app\'s current exceptions in config/testkit.php so only new ones fail';

    public function handle(RouteGuards $routes, SourceCalls $calls, LoadingFeedback $loading): int
    {
        $file = config_path('testkit.php');

        if (! is_file($file)) {
            $this->components->error('config/testkit.php is missing — run php artisan testkit:install first.');

            return self::FAILURE;
        }

        $locations = fn (array $found) => array_values(array_unique(array_map(fn (string $f) => (string) strtok($f, ' '), $found)));

        $lists = [
            "'public'" => array_values(array_unique([...(array) config('testkit.routes.public'), ...$routes->unguarded()])),
            "'debug_calls.allow'" => $locations($calls->find((array) config('testkit.debug_calls.functions'), ['app', 'routes', 'database', 'config'])),
            "'env_calls.allow'" => $locations($calls->find(['env'], ['app', 'routes', 'database', 'resources'])),
            "'loading.allow'" => $loading->missing(),
        ];

        $config = (string) file_get_contents($file);
        $config = $this->replace($config, 'routes', 'public', $lists["'public'"]);
        $config = $this->replace($config, 'debug_calls', 'allow', $lists["'debug_calls.allow'"]);
        $config = $this->replace($config, 'env_calls', 'allow', $lists["'env_calls.allow'"]);
        $config = $this->replace($config, 'loading', 'allow', $lists["'loading.allow'"]);
        file_put_contents($file, $config);

        foreach ($lists as $name => $entries) {
            $this->components->twoColumnDetail(trim($name, "'"), (string) count($entries));
        }

        return self::SUCCESS;
    }

    /**
     * Replace one list inside one section of the published config, keeping
     * everything else — comments included — as it was.
     *
     * @param  list<string>  $entries
     */
    private function replace(string $config, string $section, string $key, array $entries): string
    {
        $list = $entries === []
            ? '[]'
            : "[\n".implode('', array_map(fn (string $e) => '            '.var_export($e, true).",\n", $entries)).'        ]';

        $pattern = "/('".preg_quote($section, '/')."'\\s*=>\\s*\\[.*?'".preg_quote($key, '/')."'\\s*=>\\s*)\\[[^\\]]*\\]/s";

        return (string) preg_replace($pattern, '${1}'.str_replace(['\\', '$'], ['\\\\', '\\$'], $list), $config, 1);
    }
}
