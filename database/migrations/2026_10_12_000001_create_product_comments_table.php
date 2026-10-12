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
        Schema::create('product_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_comments')->cascadeOnDelete();
            $table->string('comment_type', 30)->default('general');
            $table->text('content');
            $table->string('attachment_url', 500)->nullable();
            $table->foreignId('alternative_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('price_suggestion', 12, 2)->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->string('status', 20)->default('published');
            $table->timestamp('edited_at')->nullable();

            // Aggregated reaction and reply counters
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('agree_count')->default(0);
            $table->unsignedInteger('need_details_count')->default(0);
            $table->unsignedInteger('good_deal_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->unsignedInteger('reports_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'parent_id', 'status', 'created_at'], 'idx_comments_feed');
            $table->index(['product_id', 'parent_id', 'status', 'helpful_count'], 'idx_comments_helpful');
            $table->index(['product_id', 'parent_id', 'status', 'reactions_count'], 'idx_comments_reactions');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_comments');
    }
};
