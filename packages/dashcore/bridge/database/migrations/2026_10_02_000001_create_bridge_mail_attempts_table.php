<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email this app tried to send, from this app's side of the wire.
 *
 * The provider can only describe what reached it. A send that threw before it
 * left — a missing key, a network failure, a mailer left on `log` in
 * production — is invisible from there, and that is exactly the failure that
 * looks like a quiet week. So each app writes its own attempt down before it
 * hands the message over, and ships the rows to `health`, which joins them to
 * what the provider said happened next.
 *
 * One row per message, not aggregated like the telemetry tables: mail volume
 * is bounded by what the app means to send, and the question asked of this
 * table is about one particular message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_mail_attempts', function (Blueprint $table) {
            $table->id();

            // Stamped onto the message as a header, so the attempt and its
            // confirmation can be paired across Laravel's two mail events.
            $table->uuid('uuid')->unique();

            $table->string('mailer')->nullable();
            $table->string('transport')->nullable();

            // sending → sent, or → unconfirmed when the send never came back.
            $table->string('status', 16)->index();

            // The provider's id for the message, when it gave one (Resend).
            $table->string('provider_id')->nullable();

            $table->string('from')->nullable();
            $table->json('to');
            $table->text('subject')->nullable();

            // What the app says this message is for, and whether its not
            // arriving is an incident. Set by the app through headers.
            $table->string('context')->nullable();
            $table->boolean('critical')->default(false);

            $table->timestamp('attempted_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reported_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_mail_attempts');
    }
};
