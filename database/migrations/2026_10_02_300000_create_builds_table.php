<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('base_ref')->nullable();
            $table->string('base_sha', 40)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->json('targets');
            $table->json('verification')->nullable();
            $table->json('changed_files')->nullable();
            $table->json('agent_report')->nullable();
            $table->unsignedInteger('targets_proven')->default(0);
            $table->unsignedInteger('tests_added')->default(0);
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->string('pr_url')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->text('error')->nullable();
            $table->json('notes')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['fleet_app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builds');
    }
};
