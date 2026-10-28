<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables `tests:demo` fills at their root. Everything below a demo row
     * (scans, surfaces, builds, library files, steps) goes with it by cascade,
     * so `tests:demo --wipe` deletes these rows and nothing else.
     *
     * @var list<string>
     */
    private const TABLES = ['fleet_apps', 'simulation_runs', 'simulation_findings', 'launches', 'workflow_runs', 'workflow_events'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('is_demo');
            });
        }
    }
};
