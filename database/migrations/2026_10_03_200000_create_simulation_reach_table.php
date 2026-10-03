<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->json('reached')->nullable()->after('findings_count');
        });

        // Everything journeys have reached in an app, in the browser's terms
        // (a page, a Livewire call, a component on screen), however often.
        Schema::create('simulation_reach', function (Blueprint $table) {
            $table->id();
            $table->string('target');
            $table->string('kind');
            $table->string('name', 500);
            $table->unsignedInteger('hits')->default(0);
            $table->foreignId('last_run_id')->nullable()->constrained('simulation_runs')->nullOnDelete();
            $table->timestamp('first_reached_at');
            $table->timestamp('last_reached_at');
            $table->timestamps();

            $table->unique(['target', 'kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_reach');

        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->dropColumn('reached');
        });
    }
};
