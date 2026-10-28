<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_runs', function (Blueprint $table) {
            $table->id();
            // The customer journey (simulation.customer_journeys key).
            $table->string('journey');
            // The stand-in fleet it walked (simulation.fleets key).
            $table->string('fleet');
            // passed, failed or refused (the guard stopped it first)
            $table->string('outcome');
            // Each stand-in that was not on the release its fleet runs live:
            // app, checkout, live (release or commit), live_sha, actual_sha.
            $table->json('drift')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();
            $table->index(['journey', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_runs');
    }
};
