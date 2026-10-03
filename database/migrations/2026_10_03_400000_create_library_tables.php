<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every test file of every app, as of the latest commit read.
        Schema::create('library_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_app_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('hash', 40);
            $table->longText('content');
            $table->unsignedInteger('lines')->default(0);
            $table->unsignedInteger('tests_count')->default(0);
            $table->string('commit_sha', 40)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('changed_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['fleet_app_id', 'path']);
        });

        // Each version a file has had, so its history can be read back.
        Schema::create('library_file_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_file_id')->constrained()->cascadeOnDelete();
            $table->string('hash', 40);
            $table->string('commit_sha', 40)->nullable();
            $table->longText('content');
            $table->unsignedInteger('lines')->default(0);
            $table->timestamp('created_at');

            $table->index(['library_file_id', 'created_at']);
        });

        // Every test: where its code is, how it has fared, what it proves.
        Schema::create('library_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('test_key', 1000);
            $table->string('test_key_hash', 40);
            $table->text('name');
            $table->string('style')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('last_outcome')->nullable();
            $table->unsignedInteger('last_assertions')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedInteger('surfaces_count')->default(0);
            $table->foreignId('last_scan_id')->nullable()->constrained('scans')->nullOnDelete();
            $table->json('history')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['fleet_app_id', 'test_key_hash']);
            $table->index(['library_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_tests');
        Schema::dropIfExists('library_file_revisions');
        Schema::dropIfExists('library_files');
    }
};
