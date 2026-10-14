<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('base_url');
            $table->json('steps');
            $table->string('frequency');
            $table->boolean('active')->default(false);
            $table->json('notify')->nullable();
            $table->unsignedSmallInteger('alert_after')->default(2);
            $table->string('last_status')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->boolean('alerting')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('run_requested_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'next_run_at']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('trigger');
            $table->unsignedSmallInteger('steps_total')->default(0);
            $table->unsignedSmallInteger('steps_passed')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('screenshots_pruned')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'id']);
        });

        Schema::create('workflow_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label');
            $table->string('action');
            $table->string('target', 500)->nullable();
            $table->string('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('url')->nullable();
            $table->string('screenshot')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['workflow_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
    }
};
