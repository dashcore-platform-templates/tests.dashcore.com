<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('kind')->default('main')->after('fleet_app_id');
            $table->unsignedInteger('pr_number')->nullable()->after('kind');
            $table->string('pr_url')->nullable()->after('pr_number');
            $table->string('pr_head_sha', 40)->nullable()->after('pr_url');
            $table->foreignId('base_scan_id')->nullable()->after('previous_scan_id')->constrained('scans')->nullOnDelete();
            $table->string('verdict')->nullable()->after('score');
            $table->json('verdict_detail')->nullable()->after('verdict');
            $table->unsignedInteger('deferrals')->default(0)->after('verdict_detail');

            $table->index(['fleet_app_id', 'kind', 'pr_number']);
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropIndex(['fleet_app_id', 'kind', 'pr_number']);
            $table->dropConstrainedForeignId('base_scan_id');
            $table->dropColumn(['kind', 'pr_number', 'pr_url', 'pr_head_sha', 'verdict', 'verdict_detail', 'deferrals']);
        });
    }
};
