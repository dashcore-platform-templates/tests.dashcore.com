<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_apps', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('host')->unique();
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('repo')->nullable();
            $table->string('local_path')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_apps');
    }
};
