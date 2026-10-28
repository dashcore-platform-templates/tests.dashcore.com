<?php

namespace App\Demo;

use App\Library\Library;
use App\Models\FleetApp;
use App\Models\LibraryTest;
use Illuminate\Support\Str;

/**
 * The demo apps' test files, written out from DemoFleet's tests as they
 * stood at each scan and kept the way a real scan keeps them — but from
 * these fixtures rather than git. A file whose tests changed between scans
 * gets a new revision; each test carries its outcome in every scan.
 */
class DemoLibrary
{
    private const CHECKS = [
        '$response->assertOk();',
        '$response->assertSessionHasNoErrors();',
        "\$response->assertDontSee('Server Error');",
        "\$response->assertHeader('content-type', 'text/html; charset=utf-8');",
    ];

    public function __construct(private Library $library) {}

    /**
     * @return int tests kept
     */
    public function seed(): int
    {
        foreach (FleetApp::query()->where('is_demo', true)->orderBy('id')->get() as $app) {
            $fixture = DemoFleet::APPS[$app->key]['tests'];

            foreach ($app->scans()->orderBy('id')->get()->values() as $n => $scan) {
                $this->library->store($app, $scan->commit_sha, $this->files($fixture, $n), $scan->finished_at, $scan);
            }
        }

        return LibraryTest::query()->whereRelation('fleetApp', 'is_demo', true)->count();
    }

    /**
     * Each test file as it read at the n-th scan: the tests it had then.
     *
     * @param  array<string, array{0: string, 1: string, 2: list<?string>, 3: int, 4: int}>  $fixture
     * @return array<string, string> path => content
     */
    private function files(array $fixture, int $n): array
    {
        $files = ['tests/TestCase.php' => "<?php\n\nnamespace Tests;\n\nuse Illuminate\\Foundation\\Testing\\TestCase as BaseTestCase;\n\nabstract class TestCase extends BaseTestCase\n{\n    //\n}\n"];
        $methods = [];

        foreach ($fixture as [$name, $file, $outcomes, $assertions]) {
            if ($outcomes[$n] !== null) {
                $methods[$file][] = $this->method($name, $outcomes[$n] === 'R' ? 0 : $assertions);
            }
        }

        foreach ($methods as $file => $bodies) {
            $class = basename($file, '.php');
            $namespace = str_replace(['tests', '/'], ['Tests', '\\'], dirname($file));

            $files[$file] = "<?php\n\nnamespace {$namespace};\n\nuse App\\Models\\User;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\TestCase;\n\n// Demo data made by tests:demo — this file is made up.\nclass {$class} extends TestCase\n{\n    use RefreshDatabase;\n\n".implode("\n", $bodies)."}\n";
        }

        return $files;
    }

    /**
     * A test method, with as many assertions as the fixture says it makes.
     */
    private function method(string $name, int $assertions): string
    {
        $method = Str::after(DemoFleet::testId('tests/X.php', $name), '::');
        $checks = $assertions === 0
            ? "        // Reaches the code but asserts nothing yet.\n"
            : implode('', array_map(fn (int $i) => '        '.self::CHECKS[$i % count(self::CHECKS)]."\n", range(0, $assertions - 1)));

        return "    public function {$method}(): void\n    {\n        // {$name}\n        \$response = \$this->actingAs(User::factory()->create())->get('/');\n\n{$checks}    }\n";
    }
}
