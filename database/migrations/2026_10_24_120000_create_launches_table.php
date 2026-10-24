<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launches', function (Blueprint $table) {
            $table->id();
            $table->string('target');
            // launched, failed or scrubbed
            $table->string('outcome');
            $table->json('countdown');
            // Each stage as `tests:journey --record` wrote it.
            $table->json('stages');
            // What was flown after, to take the practice client down again.
            $table->json('teardown')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();
            $table->index(['target', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launches');
    }
};
