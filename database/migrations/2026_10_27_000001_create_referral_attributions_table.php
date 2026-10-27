<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_attributions', function (Blueprint $table) {
            $table->id();
            // Raw bearer/visitor secrets are returned to the browser, never persisted.
            $table->char('token_hash', 64)->unique();
            $table->char('visitor_token_hash', 64);
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Keep a revoked/deleted Pick's receipt so it cannot turn into another source.
            $table->foreignId('user_pick_id')->nullable()->constrained('user_picks')->nullOnDelete();
            $table->foreignId('recommender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->timestamp('expires_at');
            $table->timestamp('locked_at')->nullable();
            $table->string('policy_version', 64);
            $table->timestamps();

            $table->index(['visitor_token_hash', 'product_id', 'visited_at', 'id'], 'referral_visitor_product_visit_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_attributions');
    }
};
