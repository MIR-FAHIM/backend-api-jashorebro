<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_buy_campaigns', fn (Blueprint $table) => $table->json('lifecycle_policy_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('group_buy_campaigns', fn (Blueprint $table) => $table->dropColumn('lifecycle_policy_snapshot'));
    }
};
