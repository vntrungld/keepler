<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('list', ['personal', 'business', 'family'])->default('personal')->after('user_id');
            $table->string('category', 50)->nullable()->after('list');
            $table->foreignId('payment_method_id')->nullable()->after('category')
                ->constrained()->nullOnDelete();
            $table->boolean('is_trial')->default(false)->after('status');
            $table->date('started_at')->nullable()->after('is_trial');
            $table->date('last_reminder_sent_for')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['list', 'category', 'is_trial', 'started_at', 'last_reminder_sent_for']);
        });
    }
};
