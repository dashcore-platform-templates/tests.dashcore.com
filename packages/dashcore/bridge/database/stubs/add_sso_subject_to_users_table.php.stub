<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where this app writes the sign-in server's permanent handle for a person.
 *
 * Without it people are matched by their verified email address on every
 * sign-in, which works until somebody changes their address — then they
 * arrive as a stranger. With it, an address is matched once and the handle
 * is what is used from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_subject')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sso_subject');
        });
    }
};
