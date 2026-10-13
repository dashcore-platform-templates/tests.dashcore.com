<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Mail;

use Dashcore\Bridge\Facades\Bridge;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\MailAttempt;
use Dashcore\Bridge\Telemetry\TelemetryReporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ships this app's mail attempts to `health`, which joins them to what the
 * provider said happened next.
 *
 * Sent to the same collector as telemetry and under the same grant
 * (`health.telemetry.write`): what an app sent is part of its account of
 * itself, every app already holds that grant, and a second one would be one
 * more edge on the fleet plan for every app to be missing.
 *
 * Frequent rather than hourly, unlike telemetry. A lead notification that did
 * not go out is worth knowing about in minutes; the collector only alerts on
 * what it has been told.
 */
final class MailReporter
{
    public const BATCH = 200;

    /**
     * How long a send may stay at `sending` before it is called unconfirmed.
     * A transport call times out well inside this; a row still waiting after
     * it threw.
     */
    public const UNCONFIRMED_AFTER_MINUTES = 5;

    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly TelemetryReporter $telemetry,
    ) {}

    /**
     * @return array{sent: int, skipped: string|null}
     */
    public function report(int $limit = self::BATCH): array
    {
        if (! (bool) config('bridge.mail.enabled', true)) {
            return ['sent' => 0, 'skipped' => 'Mail recording is switched off in this app\'s config.'];
        }

        if (! $this->identity->isComplete()) {
            return ['sent' => 0, 'skipped' => 'This app has no bridge identity yet, so it cannot sign a report.'];
        }

        MailAttempt::query()
            ->where('status', 'sending')
            ->where('attempted_at', '<', Carbon::now()->subMinutes(self::UNCONFIRMED_AFTER_MINUTES))
            ->update(['status' => 'unconfirmed']);

        $sent = 0;

        while ($sent < $limit) {
            $batch = MailAttempt::query()
                ->whereNull('reported_at')
                ->where('status', '!=', 'sending')
                ->orderBy('id')
                ->limit(min(self::BATCH, $limit - $sent))
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $skipped = $this->send($batch);

            if ($skipped !== null) {
                return ['sent' => $sent, 'skipped' => $skipped];
            }

            $sent += $batch->count();
        }

        return ['sent' => $sent, 'skipped' => null];
    }

    /** Reported attempts older than the telemetry retention window. */
    public function prune(): int
    {
        return MailAttempt::query()
            ->whereNotNull('reported_at')
            ->where('attempted_at', '<', Carbon::now()->subDays((int) config('bridge.telemetry.retention_days', 7)))
            ->delete();
    }

    /**
     * @param  Collection<int, MailAttempt>  $batch
     */
    private function send(Collection $batch): ?string
    {
        try {
            $response = Bridge::to($this->telemetry->collector())->timeout(20)->post('/emails', [
                'emails' => $batch->map(fn (MailAttempt $attempt) => [
                    'remote_id' => $attempt->id,
                    'mailer' => $attempt->mailer,
                    'transport' => $attempt->transport,
                    'status' => $attempt->status,
                    'provider_id' => $attempt->provider_id,
                    'from' => $attempt->from,
                    'to' => $attempt->to,
                    'subject' => $attempt->subject,
                    'context' => $attempt->context,
                    'critical' => $attempt->critical,
                    'attempted_at' => $attempt->attempted_at?->toIso8601String(),
                    'sent_at' => $attempt->sent_at?->toIso8601String(),
                ])->values()->all(),
            ]);
        } catch (Throwable $e) {
            return 'Could not reach the collector: '.$e->getMessage();
        }

        if (! $response->successful()) {
            return 'The collector refused the report: HTTP '.$response->status();
        }

        MailAttempt::query()->whereIn('id', $batch->pluck('id'))->update(['reported_at' => Carbon::now()]);

        return null;
    }
}
