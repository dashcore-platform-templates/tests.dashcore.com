<?php

namespace App\Simulation;

use App\Enums\FindingKind;
use App\Models\Setting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Watches the server side of a target while the simulation works it: new
 * errors in its Laravel log and new failed jobs become findings, and its
 * queue and database are measured for the load panel. Only ever reads. The
 * first look at an app starts from where it is now, so history is not
 * reported as news.
 */
class Monitor
{
    private const ERROR = '/^\[(\d{4}-\d{2}-\d{2}[^\]]*)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/';

    /** Never read more than this much new log in one look. */
    private const MAX_READ_BYTES = 2_000_000;

    public function __construct(
        private Simulation $simulation,
        private Findings $findings,
    ) {}

    public function check(string $target): void
    {
        $this->checkLog($target);
        $this->checkFailedJobs($target);
    }

    /**
     * The target's queue and database, as they stand.
     *
     * @return array{waiting: ?int, failed: ?int, database_bytes: ?int, error: ?string}
     */
    public function health(string $target): array
    {
        try {
            $db = $this->database($target);

            if ($db === null) {
                return ['waiting' => null, 'failed' => null, 'database_bytes' => null, 'error' => null];
            }

            $schema = $db->getSchemaBuilder();

            return [
                'waiting' => $schema->hasTable('jobs') ? $db->table('jobs')->count() : null,
                'failed' => $schema->hasTable('failed_jobs') ? $db->table('failed_jobs')->count() : null,
                'database_bytes' => (int) ($db->selectOne('select pg_database_size(current_database()) as size')->size ?? 0) ?: null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['waiting' => null, 'failed' => null, 'database_bytes' => null, 'error' => Str::limit($e->getMessage(), 200)];
        }
    }

    private function checkLog(string $target): void
    {
        $checkout = config("simulation.targets.{$target}.checkout");
        $path = is_string($checkout) ? rtrim($checkout, '/').'/storage/logs/laravel.log' : null;

        if ($path === null || ! is_file($path)) {
            return;
        }

        $key = "simulation.monitor.{$target}.log";
        $size = filesize($path);
        $offset = Setting::get($key);

        // First look, or the log was rotated or cleared: start from now.
        if (! is_int($offset) || $offset > $size) {
            Setting::set($key, $size);

            return;
        }

        if ($offset === $size) {
            return;
        }

        $handle = fopen($path, 'rb');
        fseek($handle, max($offset, $size - self::MAX_READ_BYTES));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        // Keep a half-written last entry for next time.
        $complete = strrpos($chunk, "\n");
        $read = $complete === false ? 0 : $complete + 1;
        Setting::set($key, max($offset, $size - self::MAX_READ_BYTES) + $read);

        foreach ($this->entries(substr($chunk, 0, $read)) as $entry) {
            $message = $this->normalise($entry['message']);

            $this->findings->recordMonitored(
                $target,
                FindingKind::LogError,
                $message,
                $message,
                "Logged at {$entry['at']}.\n".$entry['body'],
            );
        }
    }

    private function checkFailedJobs(string $target): void
    {
        $db = $this->database($target);

        if ($db === null || ! $db->getSchemaBuilder()->hasTable('failed_jobs')) {
            return;
        }

        $key = "simulation.monitor.{$target}.failed_job";
        $last = Setting::get($key);
        $newest = (int) $db->table('failed_jobs')->max('id');

        if (! is_int($last) || $last > $newest) {
            Setting::set($key, $newest);

            return;
        }

        $db->table('failed_jobs')->where('id', '>', $last)->orderBy('id')->limit(50)->get()->each(function (object $job) use ($target) {
            $name = json_decode((string) $job->payload, true)['displayName'] ?? 'A job';
            $reason = $this->normalise(strtok((string) $job->exception, "\n") ?: 'no exception recorded');

            $this->findings->recordMonitored(
                $target,
                FindingKind::FailedJob,
                "{$name}|{$reason}",
                class_basename($name).' failed: '.$reason,
                "On the {$job->queue} queue at {$job->failed_at}.\n".Str::limit((string) $job->exception, 3000),
            );
        });

        Setting::set($key, $newest);
    }

    /**
     * @return list<array{at: string, message: string, body: string}>
     */
    private function entries(string $text): array
    {
        $entries = [];
        $current = null;

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = preg_match(self::ERROR, $line, $m)
                    ? ['at' => $m[1], 'message' => Str::before($m[3], ' {"'), 'body' => $line]
                    : null;

                continue;
            }

            if ($current !== null && substr_count($current['body'], "\n") < 15) {
                $current['body'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function normalise(string $message): string
    {
        $message = preg_replace('#https?://\S+#', '<url>', $message);
        $message = preg_replace('/\d+/', '#', $message);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message)), 300);
    }

    private function database(string $target): ?ConnectionInterface
    {
        $database = config("simulation.targets.{$target}.database");

        return is_string($database) && $database !== '' ? $this->simulation->targetDatabase($target, $database) : null;
    }
}
