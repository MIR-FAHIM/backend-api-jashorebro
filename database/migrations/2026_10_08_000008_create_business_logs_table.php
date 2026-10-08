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
        if (! Schema::hasTable('business_logs')) {
            Schema::create('business_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event', 100);
                $table->string('category', 50); // authentication, order, product
                $table->string('outcome', 20); // success, failure
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_role', 50)->nullable();
                $table->string('subject_type', 100)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->text('message')->nullable();
                $table->json('metadata')->nullable();
                $table->string('request_id', 100)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();

                // Optimized compound and single indexes for report querying
                $table->index('occurred_at');
                $table->index('event');
                $table->index('category');
                $table->index('outcome');
                $table->index('actor_user_id');
                $table->index(['subject_type', 'subject_id']);
                $table->index('request_id');
                $table->index(['category', 'occurred_at']);
                $table->index(['outcome', 'occurred_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_logs');
    }
};
