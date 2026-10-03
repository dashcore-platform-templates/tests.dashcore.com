<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('livewire_requests')->default(0)->after('duration_ms');
            $table->unsignedInteger('livewire_ms')->nullable()->after('livewire_requests');
            $table->boolean('feedback')->nullable()->after('livewire_ms');
        });

        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('findings_count')->default(0)->after('steps_passed');
        });

        Schema::create('simulation_findings', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('fingerprint', 500);
            $table->string('target');
            $table->string('journey')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('status');
            $table->unsignedInteger('occurrences')->default(0);
            $table->unsignedInteger('clean_runs')->default(0);
            $table->foreignId('last_run_id')->nullable()->constrained('simulation_runs')->nullOnDelete();
            $table->unsignedSmallInteger('last_position')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'fingerprint']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_findings');

        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->dropColumn('findings_count');
        });

        Schema::table('simulation_steps', function (Blueprint $table) {
            $table->dropColumn(['livewire_requests', 'livewire_ms', 'feedback']);
        });
    }
};
