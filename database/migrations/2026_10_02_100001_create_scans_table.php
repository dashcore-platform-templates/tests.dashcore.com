<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('ref')->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('commit_subject')->nullable();
            $table->string('framework')->nullable();
            $table->string('test_database')->nullable();
            $table->unsignedInteger('tests_total')->default(0);
            $table->unsignedInteger('tests_passed')->default(0);
            $table->unsignedInteger('tests_failed')->default(0);
            $table->unsignedInteger('tests_skipped')->default(0);
            $table->unsignedInteger('tests_risky')->default(0);
            $table->unsignedInteger('assertions')->default(0);
            $table->unsignedInteger('surfaces_total')->default(0);
            $table->unsignedInteger('surfaces_tested')->default(0);
            $table->unsignedInteger('surfaces_weak')->default(0);
            $table->unsignedInteger('surfaces_missing')->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->json('notes')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['fleet_app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
