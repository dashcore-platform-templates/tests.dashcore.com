<?php

namespace Tests\Feature\Simulation;

use App\Simulation\JourneyWalker;
use App\Simulation\Script;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * The real browser (simulator/run.mjs) walking one script across two apps:
 * two throwaway PHP servers on their own ports. A value remembered off the
 * first app's form is typed into the second, and a visit on each opens on
 * that app — the two things a customer journey needs that a one-app journey
 * never did.
 */
class CrossAppBrowserTest extends TestCase
{
    private string $root;

    /** @var list<SymfonyProcess> */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(base_path('simulator/node_modules/playwright'))) {
            $this->markTestSkipped('The browser is not installed here: npm install --prefix simulator.');
        }

        $this->root = sys_get_temp_dir().'/pg-cross-'.bin2hex(random_bytes(4));
        config(['simulation.screenshots' => "{$this->root}/shots", 'simulation.step_timeout_ms' => 5000, 'simulation.run_timeout' => 60]);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(1);
        }

        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * Serve a folder holding one page, index.php, and return its address.
     */
    private function serve(string $name, string $page): string
    {
        File::ensureDirectoryExists("{$this->root}/{$name}");
        File::put("{$this->root}/{$name}/index.php", $page);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = new SymfonyProcess([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$this->root}/{$name}"]);
        $server->start();
        $this->servers[] = $server;

        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        return "http://127.0.0.1:{$port}";
    }

    public function test_a_value_remembered_on_one_app_is_typed_into_another(): void
    {
        // The website: a form whose challenge token arrives a moment after
        // the page does, the way Turnstile's does.
        $website = $this->serve('website', <<<'HTML'
            <h1>Website</h1>
            <input id="email">
            <div id="challenge"><input type="hidden" name="token"></div>
            <script>setTimeout(() => document.querySelector('[name=token]').value = 'XXXX.DUMMY.TOKEN', 400)</script>
            HTML);

        // Sales: a search that shows what was searched for.
        $sales = $this->serve('sales', <<<'HTML'
            <h1>Sales</h1>
            <input id="search" oninput="document.getElementById('found').textContent = 'Found ' + this.value">
            <p id="found"></p>
            HTML);

        $email = 'journey+'.bin2hex(random_bytes(4)).'@example.com';
        $script = (new Script)
            ->on('website')
            ->visit('/', 'The prospect opens the website')
            ->see('Website', 'It is the website')
            ->fill('#email', $email, 'They type their email')
            ->remember('#email', 'email', 'The form holds it')
            ->filled('#challenge input[name="token"]', 'The challenge lets them through')
            ->on('sales')
            ->visit('/', 'A rep opens sales')
            ->see('Sales', 'It is sales, not the website')
            ->fill('#search', '{email}', 'The rep searches for {email}')
            ->see('Found {email}', 'Sales shows {email}');

        $seen = [];
        $result = app(JourneyWalker::class)->walk($script, ['website' => $website, 'sales' => $sales], "{$this->root}/shots", function (array $step) use (&$seen) {
            $seen[] = $step['label'];
        });

        $this->assertTrue($result->passed(), $result->error ?? json_encode($result->steps));
        $this->assertSame($email, $result->remembered['email']);
        $this->assertContains("The rep searches for {$email}", $seen);
        $this->assertStringStartsWith($sales, (string) $result->steps[6]['url']);
        $this->assertStringStartsWith($website, (string) $result->steps[0]['url']);
    }

    public function test_a_field_that_never_fills_fails_its_step(): void
    {
        $website = $this->serve('website', '<input type="hidden" name="token">');

        $script = (new Script)->on('website')
            ->visit('/', 'Open')
            ->filled('input[name="token"]', 'Wait for the token')
            ->see('never', 'Not reached');

        $result = app(JourneyWalker::class)->walk($script, ['website' => $website], "{$this->root}/shots", fn () => null);

        $this->assertFalse($result->passed());
        $this->assertSame('failed', $result->steps[1]['status']);
        $this->assertStringContainsString('never got a value', (string) $result->steps[1]['error']);
        $this->assertCount(2, $result->steps);
    }
}
