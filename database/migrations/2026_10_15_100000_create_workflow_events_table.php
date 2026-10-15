<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workflow_name')->nullable();
            $table->string('type');
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['workflow_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
    }
};
