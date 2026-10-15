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
        if (! Schema::hasTable('feed_feedbacks')) {
            Schema::create('feed_feedbacks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('feed_item_id', 100);
                $table->string('content_type', 50); // recommendation, discussion, drop, shop, catalog
                $table->unsignedBigInteger('source_id')->index();
                $table->string('action', 50); // hide, less_like_this, mute_author, mute_topic
                $table->unsignedBigInteger('target_author_id')->nullable()->index();
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'action']);
                $table->index(['user_id', 'content_type', 'source_id']);
                $table->index(['user_id', 'target_author_id']);
            });
        }

        if (! Schema::hasTable('feed_impressions')) {
            Schema::create('feed_impressions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('session_id', 100)->nullable();
                $table->string('event_type', 50)->default('impression'); // impression, click, view_details, save_pick, add_to_cart
                $table->string('feed_item_id', 100);
                $table->string('content_type', 50);
                $table->unsignedBigInteger('source_id');
                $table->string('tab', 50)->default('for_you'); // for_you, following, trending
                $table->integer('position')->nullable();
                $table->string('ranking_version', 20)->default('v1');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'feed_item_id', 'event_type']);
                $table->index(['content_type', 'source_id']);
                $table->index(['tab', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_impressions');
        Schema::dropIfExists('feed_feedbacks');
    }
};
