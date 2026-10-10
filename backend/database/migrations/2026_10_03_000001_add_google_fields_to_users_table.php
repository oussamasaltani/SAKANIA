<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything "Continue with Google" needs on an existing user row.
 *
 * google_id is Google's own immutable subject id, so an account that has signed
 * in with Google before is recognised without ever trusting the email string.
 * email_verified_at records that Google confirmed the address, which is the
 * identity the whole flow is built on.
 *
 * password_hash becomes nullable because a Google-only account has no password:
 * it signs in through Google and, until it sets one of its own, no password can
 * match the empty hash. Accounts that already have a password keep it and keep
 * signing in with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 191)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
        });

        // Kept apart from the columns above: changing an existing column rebuilds
        // the table on SQLite, which is simpler on its own.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_hash', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reversible while no Google-only account exists: such a row has no
        // password to put back, so it has to be given one (or removed) first.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_hash', 255)->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'email_verified_at']);
        });
    }
};
