<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->json('funding_snapshot')->nullable();
        });
        Schema::create('order_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('event_key', 120)->unique();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_histories');
        Schema::table('payout_requests', fn (Blueprint $table) => $table->dropColumn('funding_snapshot'));
    }
};
