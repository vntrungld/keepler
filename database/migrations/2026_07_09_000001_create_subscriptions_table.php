<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_vnd');
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->date('next_renewal_date');
            $table->enum('status', ['active', 'pending_cancel', 'cancelled'])->default('active');
            $table->string('cancel_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
