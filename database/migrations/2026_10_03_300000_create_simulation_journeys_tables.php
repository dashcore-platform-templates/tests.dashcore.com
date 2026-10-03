<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An agent reading an app's source to draft journeys nobody wrote yet.
        Schema::create('simulation_explorations', function (Blueprint $table) {
            $table->id();
            $table->string('target');
            $table->text('focus')->nullable();
            $table->string('status');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('drafted')->default(0);
            $table->unsignedSmallInteger('rejected')->default(0);
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->text('report')->nullable();
            $table->text('error')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Journeys kept as data: drafted by the explorer, proven on trial in
        // the real browser before they join the rotation.
        Schema::create('simulation_journeys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('target');
            $table->string('name');
            $table->text('why')->nullable();
            $table->json('roles');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->json('steps');
            $table->string('summary')->nullable();
            $table->string('status');
            $table->foreignId('exploration_id')->nullable()->constrained('simulation_explorations')->nullOnDelete();
            $table->unsignedSmallInteger('trial_passes')->default(0);
            $table->unsignedSmallInteger('trial_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('last_run_id')->nullable()->constrained('simulation_runs')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'target']);
        });

        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->boolean('trial')->default(false)->after('target');
        });

        // What a step acted on (a selector, or the path it visited), so a
        // finding about an element is the same finding in every journey.
        Schema::table('simulation_steps', function (Blueprint $table) {
            $table->string('target', 500)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('simulation_steps', function (Blueprint $table) {
            $table->dropColumn('target');
        });

        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->dropColumn('trial');
        });

        Schema::dropIfExists('simulation_journeys');
        Schema::dropIfExists('simulation_explorations');
    }
};
