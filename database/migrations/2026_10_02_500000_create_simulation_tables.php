<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('journey');
            $table->string('journey_name');
            $table->string('target');
            $table->string('persona_name');
            $table->string('persona_email');
            $table->string('status');
            $table->string('summary')->nullable();
            $table->unsignedSmallInteger('steps_total')->default(0);
            $table->unsignedSmallInteger('steps_passed')->default(0);
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('screenshots_pruned')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index('created_at');
        });

        Schema::create('simulation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label');
            $table->string('action');
            $table->string('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('url')->nullable();
            $table->string('screenshot')->nullable();
            $table->text('error')->nullable();
            $table->json('console')->nullable();
            $table->timestamps();

            $table->unique(['simulation_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_steps');
        Schema::dropIfExists('simulation_runs');
    }
};
