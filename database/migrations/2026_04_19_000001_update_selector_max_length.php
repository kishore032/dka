<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selector column is now explicitly bounded to 63 characters per the DKA spec
 * (previously the application enforced a 32-character limit; the spec requires
 * a maximum of 63 characters). The column is changed from TEXT to VARCHAR(63).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_keys', function (Blueprint $table) {
            $table->string('selector', 63)->change();
        });
    }

    public function down(): void
    {
        Schema::table('public_keys', function (Blueprint $table) {
            $table->text('selector')->change();
        });
    }
};
