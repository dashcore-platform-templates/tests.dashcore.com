<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Mail;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Writes down every email this app sends, through Laravel's own mail events,
 * so no app has to change a line to be covered.
 *
 * `MessageSending` fires before the message is handed to the transport;
 * `MessageSent` only after the transport returned. Laravel fires nothing when
 * a send throws, so a row still at `sending` a little later *is* the failure —
 * the reporter files it as unconfirmed. That is the case this exists for: a
 * provider can only describe what reached it.
 *
 * Under the same rule as the telemetry recorder: recording must never break
 * the send it records. Every entry point swallows its own failures, and rows
 * are written through the query builder so they do not announce themselves as
 * model events in an app that listens to those.
 */
final class MailRecorder
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, $this->onSending(...));
        $events->listen(MessageSent::class, $this->onSent(...));
    }

    /**
     * Write down an attempt before the message is handed over. Returns the
     * attempt's id for `confirm()`, or null when nothing was recorded.
     *
     * Public for apps that send without Laravel's mailer — a direct POST to
     * a provider's API — so their mail is in the ledger too:
     *
     *     $attempt = $recorder->begin(['to' => [...], 'subject' => ..., 'context' => 'lead-notification', 'critical' => true]);
     *     $id = Http::post(...)->throw()->json('id');
     *     $recorder->confirm($attempt, $id);
     *
     * An attempt never confirmed is reported as unconfirmed.
     *
     * @param  array{from?: string|null, to?: list<string>, subject?: string|null, context?: string|null, critical?: bool, mailer?: string|null, transport?: string|null}  $message
     */
    public function begin(array $message): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $uuid = (string) Str::uuid();

            DB::table('bridge_mail_attempts')->insert([
                'uuid' => $uuid,
                'mailer' => $message['mailer'] ?? null,
                'transport' => $message['transport'] ?? null,
                'status' => 'sending',
                'from' => $message['from'] ?? null,
                'to' => json_encode(array_values($message['to'] ?? [])),
                'subject' => $message['subject'] ?? null,
                'context' => $message['context'] ?? null,
                'critical' => (bool) ($message['critical'] ?? false),
                'attempted_at' => Carbon::now(),
            ]);

            return $uuid;
        } catch (Throwable) {
            // A missing row, never a failed send.
            return null;
        }
    }

    /** The message went: mark the attempt sent, with the provider's id. */
    public function confirm(?string $attempt, ?string $providerId = null): void
    {
        if ($attempt === null || ! $this->enabled()) {
            return;
        }

        try {
            DB::table('bridge_mail_attempts')->where('uuid', $attempt)->update([
                'status' => 'sent',
                'provider_id' => $providerId,
                'sent_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
            // As above.
        }
    }

    private function onSending(MessageSending $event): void
    {
        if (! $this->enabled() || ! $event->message instanceof Email) {
            return;
        }

        $message = $event->message;
        $headers = $message->getHeaders();
        $mailer = isset($event->data['mailer']) ? (string) $event->data['mailer'] : null;

        try {
            $uuid = $this->begin([
                'mailer' => $mailer,
                'transport' => $mailer !== null ? config("mail.mailers.{$mailer}.transport") : null,
                'from' => $this->addresses($message->getFrom())[0] ?? null,
                'to' => [
                    ...$this->addresses($message->getTo()),
                    ...$this->addresses($message->getCc()),
                    ...$this->addresses($message->getBcc()),
                ],
                'subject' => $message->getSubject(),
                'context' => $headers->get(MailContext::CONTEXT)?->getBodyAsString(),
                'critical' => in_array($headers->get(MailContext::CRITICAL)?->getBodyAsString(), ['1', 'true'], true),
            ]);

            if ($uuid !== null) {
                $headers->remove(MailContext::ATTEMPT);
                $headers->addTextHeader(MailContext::ATTEMPT, $uuid);
            }
        } catch (Throwable) {
            // As above.
        }
    }

    private function onSent(MessageSent $event): void
    {
        try {
            $headers = $event->sent->getOriginalMessage()->getHeaders();

            $this->confirm(
                $headers->get(MailContext::ATTEMPT)?->getBodyAsString(),
                $headers->get('X-Resend-Email-ID')?->getBodyAsString(),
            );
        } catch (Throwable) {
            // As above.
        }
    }

    private function enabled(): bool
    {
        return (bool) config('bridge.mail.enabled', true);
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $address->getAddress(), $addresses);
    }
}
