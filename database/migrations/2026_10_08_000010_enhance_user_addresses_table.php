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
        Schema::table('user_addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('user_addresses', 'country')) {
                $table->string('country', 100)->default('Bangladesh')->after('recipient_phone');
            }
            if (! Schema::hasColumn('user_addresses', 'landmark')) {
                $table->string('landmark', 255)->nullable()->after('sub_district_thana');
            }
            if (! Schema::hasColumn('user_addresses', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('landmark');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            $dropColumns = [];
            if (Schema::hasColumn('user_addresses', 'country')) {
                $dropColumns[] = 'country';
            }
            if (Schema::hasColumn('user_addresses', 'landmark')) {
                $dropColumns[] = 'landmark';
            }
            if (Schema::hasColumn('user_addresses', 'postal_code')) {
                $dropColumns[] = 'postal_code';
            }
            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
