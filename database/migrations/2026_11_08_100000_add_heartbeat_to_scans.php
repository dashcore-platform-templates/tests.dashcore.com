<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A running scan stamps `heartbeat_at` while it works, with the worker
     * process that runs it, so `tests:reap-scans` can tell a scan whose
     * worker died from one that is only slow (REQ-683).
     */
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedInteger('worker_pid')->nullable();
            $table->string('worker_host')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['heartbeat_at', 'worker_pid', 'worker_host']);
        });
    }
};
