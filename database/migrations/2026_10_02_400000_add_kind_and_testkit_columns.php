<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->string('kind')->default('tests')->after('fleet_app_id');
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->string('testkit_version')->nullable()->after('framework');
        });
    }

    public function down(): void
    {
        Schema::table('builds', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('scans', fn (Blueprint $table) => $table->dropColumn('testkit_version'));
    }
};
