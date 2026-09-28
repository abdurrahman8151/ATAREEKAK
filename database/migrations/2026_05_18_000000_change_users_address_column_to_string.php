<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // T3-6: raw MySQL-only statement(s) below. Skip cleanly on other
        // drivers instead of fataling a fresh migrate (no-op on MySQL).
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY address VARCHAR(100) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY address ENUM(
            'دمشق','درعا','القنيطرة','السويداء','ريف دمشق',
            'حمص','حماة','اللاذقية','طرطوس','حلب',
            'ادلب','الحسكة','الرقة','دير الزور'
        ) NULL");
    }
};
