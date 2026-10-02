<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_app_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('identifier', 512);
            $table->string('label', 512);
            $table->string('file')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->json('meta')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('last_scan_id')->nullable()->constrained('scans')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['fleet_app_id', 'kind', 'identifier']);
            $table->index(['fleet_app_id', 'status']);
        });

        Schema::create('scan_surfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('surface_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('tests_count')->default(0);
            $table->json('evidence')->nullable();

            $table->unique(['scan_id', 'surface_id']);
        });

        Schema::create('scan_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->text('test_id');
            $table->string('file')->nullable();
            $table->text('name');
            $table->string('outcome');
            $table->unsignedInteger('assertions')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('surfaces_count')->default(0);
            $table->text('message')->nullable();

            $table->index(['scan_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_tests');
        Schema::dropIfExists('scan_surfaces');
        Schema::dropIfExists('surfaces');
    }
};
