<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('renewal_reminders_enabled')->default(true);
            $table->unsignedTinyInteger('reminder_days_before')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminders_enabled', 'reminder_days_before']);
        });
    }
};
