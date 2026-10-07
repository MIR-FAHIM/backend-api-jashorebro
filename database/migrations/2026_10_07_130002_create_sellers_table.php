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
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('store_name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('contact_phone');
            $table->string('contact_email')->nullable();
            $table->string('district')->default('Jashore');
            $table->string('upazila')->nullable();
            $table->text('address')->nullable();
            $table->string('trade_license_number')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'district']);
            $table->index('rating_avg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
