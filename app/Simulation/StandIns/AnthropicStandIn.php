<?php

namespace App\Simulation\StandIns;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A local stand-in for the Anthropic API, so a journey's AI steps — the
 * onboarding call's drafted answers, ceo's first briefing — need no key,
 * cost nothing, and say the same thing every run. A stand-in app's command
 * is run with env() added: ANTHROPIC_BASE_URL points it here, and the key is
 * a placeholder nothing checks.
 *
 * It is `php -S` on a free loopback port with a router that answers in the
 * Messages API's shape (resources/simulation/anthropic-stand-in.php): each
 * reply is chosen by text the request contains, and every request is kept,
 * so a journey can show what the app asked.
 */
class AnthropicStandIn
{
    private ?InvokedProcess $server = null;

    private int $port = 0;

    private string $dir;

    /**
     * @param  list<array{when: string, text: string}>  $replies  the first whose `when` the request contains answers it
     */
    public function __construct(private array $replies = [])
    {
        $this->dir = storage_path('app/anthropic-stand-in/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)));
    }

    /**
     * Starts it, and waits until it answers.
     *
     * @throws RuntimeException when it does not come up
     */
    public function start(): self
    {
        File::ensureDirectoryExists($this->dir);
        File::put("{$this->dir}/replies.json", json_encode($this->replies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->port = $this->freePort();
        $this->server = Process::env(['STAND_IN_DIR' => $this->dir])
            ->start([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", resource_path('simulation/anthropic-stand-in.php')]);

        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            try {
                Http::timeout(1)->get($this->url().'/up');

                return $this;
            } catch (Throwable) {
                usleep(100_000);
            }
        }

        $this->stop();

        throw new RuntimeException('The Anthropic stand-in did not start on 127.0.0.1:'.$this->port.'.');
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * What a stand-in app's command runs with, so its model is this.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        return ['ANTHROPIC_BASE_URL' => $this->url(), 'ANTHROPIC_API_KEY' => 'stand-in'];
    }

    /**
     * Every request it was sent, in order, as the app sent it.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        return collect(File::glob("{$this->dir}/requests/*.json"))
            ->sort()
            ->map(fn (string $file): array => (array) json_decode((string) File::get($file), true))
            ->values()
            ->all();
    }

    public function stop(): void
    {
        $this->server?->signal(SIGTERM);
        $this->server = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error) ?: throw new RuntimeException("No free port for the Anthropic stand-in: {$error}");
        $port = (int) Str::afterLast((string) stream_socket_get_name($socket, false), ':');
        fclose($socket);

        return $port;
    }
}
