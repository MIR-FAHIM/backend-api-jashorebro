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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('audience', 20)->default('customer')->index(); // 'admin' or 'customer'
            $table->string('event', 100)->nullable()->index();
            $table->string('title');
            $table->text('message');
            $table->nullableMorphs('subject'); // subject_type, subject_id
            $table->string('action_url')->nullable();
            $table->string('dedup_key')->nullable()->index();
            $table->json('data');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            // High performance composite indexes
            $table->index(['notifiable_type', 'notifiable_id', 'audience', 'read_at'], 'idx_notif_recipient_aud_read');
            $table->index(['notifiable_type', 'notifiable_id', 'dedup_key'], 'idx_notif_recipient_dedup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
