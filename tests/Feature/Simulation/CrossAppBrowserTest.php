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

    public function test_a_link_is_read_from_the_newest_email_to_the_prospect_and_opened(): void
    {
        $delivery = $this->serve('delivery', '<h1>Opened <?= htmlspecialchars($_GET["deal"] ?? "nothing") ?></h1>');

        // Sales' log: the mail log driver writes each message as one entry.
        // An older email to the prospect, and a newer one to somebody else,
        // must both be passed over for the newest one to the prospect.
        $email = 'journey+'.bin2hex(random_bytes(4)).'@example.com';
        $entry = fn (string $at, string $to, string $deal) => "[{$at}] local.DEBUG: From: Rep <rep@example.com>\r\nTo: {$to}\r\nSubject: Your payment link\r\n\r\nHere's your payment link:\r\n{$delivery}/pay/1?deal={$deal}&amp;signature=abc\r\n<a href=\"{$delivery}/pay/1?deal={$deal}&amp;signature=abc\">Pay</a>\r\n";
        File::ensureDirectoryExists("{$this->root}/sales/storage/logs");
        File::put("{$this->root}/sales/storage/logs/laravel.log", implode('', [
            "[2026-10-06 10:00:00] local.INFO: Something unrelated\n",
            $entry('2026-10-06 10:00:01', $email, 'old'),
            $entry('2026-10-06 10:00:03', $email, 'new'),
            $entry('2026-10-06 10:00:04', 'someone.else@example.com', 'theirs'),
            "[2026-10-06 10:00:05] local.INFO: Something after\n",
        ]));

        $script = (new Script)
            ->on('delivery')
            ->visit('/', 'Something is open')
            ->on('sales')
            ->mail($email, '\bhttps?://[^\s"\'<>]+/pay/\d+\?[^\s"\'<>]+', 'pay_link', 'The link arrives', seconds: 2)
            ->on('delivery')
            ->visit('{pay_link}', 'They open it')
            ->see('Opened new', 'It is the newest one');

        $result = app(JourneyWalker::class)->walk($script, ['delivery' => $delivery], "{$this->root}/shots", fn () => null, ['sales' => "{$this->root}/sales/storage/logs"]);

        $this->assertTrue($result->passed(), $result->error ?? json_encode($result->steps));
        $this->assertSame("{$delivery}/pay/1?deal=new&signature=abc", $result->remembered['pay_link']);
    }

    public function test_an_email_that_never_arrives_fails_its_step(): void
    {
        $delivery = $this->serve('delivery', '<h1>Hi</h1>');
        File::ensureDirectoryExists("{$this->root}/sales/storage/logs");
        File::put("{$this->root}/sales/storage/logs/laravel.log", "[2026-10-06 10:00:00] local.INFO: Nothing to see\n");

        $script = (new Script)->on('delivery')->visit('/', 'Open')
            ->on('sales')->mail('journey+none@example.com', '/pay/\d+', 'pay_link', 'The link arrives', seconds: 1);

        $result = app(JourneyWalker::class)->walk($script, ['delivery' => $delivery], "{$this->root}/shots", fn () => null, ['sales' => "{$this->root}/sales/storage/logs"]);

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('No email to journey+none@example.com', (string) $result->steps[1]['error']);
    }

    public function test_text_a_webhook_writes_later_is_waited_for_by_reloading(): void
    {
        // The page shows the project only from its third load on, as a
        // portal does once the webhook has landed.
        $portal = $this->serve('portal', <<<'HTML'
            <?php $n = (int) @file_get_contents(__DIR__.'/loads') + 1; file_put_contents(__DIR__.'/loads', $n); ?>
            <h1>Dashboard</h1>
            <?php if ($n >= 3): ?><p>Fox Workshop — Operations setup</p><?php endif; ?>
            HTML);

        $script = (new Script)->on('portal')
            ->visit('/', 'They land on the dashboard')
            ->seeWithin('Fox Workshop — Operations setup', 20, 'Their project shows')
            ->seeWithin('Something that never comes', 2, 'Not there');

        $result = app(JourneyWalker::class)->walk($script, ['portal' => $portal], "{$this->root}/shots", fn () => null);

        $this->assertSame('passed', $result->steps[1]['status'], (string) $result->steps[1]['error']);
        $this->assertGreaterThanOrEqual(3, (int) file_get_contents("{$this->root}/portal/loads"));
        $this->assertSame('failed', $result->steps[2]['status']);
        $this->assertStringContainsString('was not on the page after 2 s of reloading', (string) $result->steps[2]['error']);
    }

    public function test_a_hosted_checkout_is_paid_with_the_test_card_and_left(): void
    {
        $app = $this->serve('app', '<h1>Paid with <?= htmlspecialchars($_GET["card"] ?? "nothing") ?> by <?= htmlspecialchars($_GET["email"] ?? "") ?></h1>');

        // A stand-in for Stripe's page, on another host (localhost, where the
        // app is 127.0.0.1): the same field ids, Link's box ticked, and a
        // submit that refuses until everything is filled and Link is off.
        $checkout = str_replace('127.0.0.1', 'localhost', $this->serve('checkout', <<<HTML
            <input id="email">
            <button data-testid="card-accordion-item-button" onclick="document.getElementById('card').hidden = false">Card</button>
            <div id="card" hidden>
              <input id="cardNumber"><input id="cardExpiry"><input id="cardCvc">
              <input id="billingName"><input id="billingPostalCode">
              <label><input id="enableStripePass" type="checkbox" checked> Save my info with Link</label>
              <button data-testid="hosted-payment-submit-button" onclick="pay()">Pay</button>
            </div>
            <p id="why"></p>
            <script>
              function pay() {
                const v = (id) => document.getElementById(id).value;
                if (document.getElementById('enableStripePass').checked) { document.getElementById('why').textContent = 'Link is on'; return; }
                if (! v('cardExpiry') || ! v('cardCvc') || ! v('billingName') || ! v('billingPostalCode')) { document.getElementById('why').textContent = 'Incomplete'; return; }
                location.href = '{$app}/?card=' + encodeURIComponent(v('cardNumber')) + '&email=' + encodeURIComponent(v('email'));
              }
            </script>
            HTML));

        $script = (new Script)->on('app')
            ->visit("{$checkout}/", 'The pay link sends them to the checkout')
            ->payByCard([
                'number' => '4242424242424242',
                'expiry' => '10 / 29',
                'cvc' => '123',
                'name' => 'Dana Fox',
                'email' => 'journey+abc@example.com',
                'postal_code' => '94107',
            ], 'They pay with the test card', seconds: 15, host: 'localhost')
            ->see('Paid with 4242424242424242 by journey+abc@example.com', 'The app has them back');

        $result = app(JourneyWalker::class)->walk($script, ['app' => $app], "{$this->root}/shots", fn () => null);

        $this->assertTrue($result->passed(), $result->error ?? json_encode($result->steps));
        $this->assertStringStartsWith($app, (string) $result->steps[2]['url']);
    }
}
