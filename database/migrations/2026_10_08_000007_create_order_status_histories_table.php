<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('order_status_histories')) {
            Schema::create('order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_type', 20)->default('system'); // admin, customer, system
                $table->text('reason')->nullable(); // Customer-visible message
                $table->text('internal_note')->nullable(); // Visible ONLY to authorized admins
                $table->string('event_key', 100)->nullable()->unique();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['order_id', 'occurred_at']);
                $table->index(['order_id', 'to_status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
