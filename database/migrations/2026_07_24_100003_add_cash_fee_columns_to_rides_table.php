<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (! Schema::hasColumn('rides', 'cash_creation_fee')) {
                $table->decimal('cash_creation_fee', 15, 2)->nullable()->after('communication_number');
            }
            if (! Schema::hasColumn('rides', 'cash_fee_deferred')) {
                $table->boolean('cash_fee_deferred')->default(false)->after('cash_creation_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $drop = array_filter(
                ['cash_creation_fee', 'cash_fee_deferred'],
                fn ($c) => Schema::hasColumn('rides', $c)
            );
            if ($drop) {
                $table->dropColumn(array_values($drop));
            }
        });
    }
};
