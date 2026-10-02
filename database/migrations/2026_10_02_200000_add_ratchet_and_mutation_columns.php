<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surfaces', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('status');
            $table->string('body_hash', 40)->nullable()->after('end_line');
            $table->string('mutation')->nullable()->after('reason');
            $table->string('mutation_hash', 40)->nullable()->after('mutation');
            $table->timestamp('mutation_checked_at')->nullable()->after('mutation_hash');
        });

        Schema::table('scan_surfaces', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('status');
            $table->string('change')->nullable()->after('reason');
            $table->string('mutation')->nullable()->after('change');
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->foreignId('previous_scan_id')->nullable()->after('fleet_app_id')->constrained('scans')->nullOnDelete();
            $table->unsignedInteger('surfaces_new_untested')->default(0)->after('surfaces_missing');
            $table->unsignedInteger('surfaces_lost')->default(0)->after('surfaces_new_untested');
            $table->unsignedInteger('surfaces_gained')->default(0)->after('surfaces_lost');
            $table->unsignedInteger('mutants_killed')->default(0)->after('surfaces_gained');
            $table->unsignedInteger('mutants_survived')->default(0)->after('mutants_killed');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_scan_id');
            $table->dropColumn(['surfaces_new_untested', 'surfaces_lost', 'surfaces_gained', 'mutants_killed', 'mutants_survived']);
        });

        Schema::table('scan_surfaces', function (Blueprint $table) {
            $table->dropColumn(['reason', 'change', 'mutation']);
        });

        Schema::table('surfaces', function (Blueprint $table) {
            $table->dropColumn(['reason', 'body_hash', 'mutation', 'mutation_hash', 'mutation_checked_at']);
        });
    }
};
