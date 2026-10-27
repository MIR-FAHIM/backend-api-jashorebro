<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earnings_ledgers', function (Blueprint $table) {
            // Nullable keys leave historical records untouched; all new events have a durable key.
            $table->string('event_key', 120)->nullable()->unique();
            $table->foreignId('source_ledger_id')->nullable()->constrained('earnings_ledgers')->nullOnDelete();
        });
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
        Schema::table('earnings_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_ledger_id');
            $table->dropUnique(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};
