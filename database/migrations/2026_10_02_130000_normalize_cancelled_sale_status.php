<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')->where('status', 'cancelled')->update(['status' => 'CANCELLED']);
    }

    public function down(): void
    {
        DB::table('sales')->where('status', 'CANCELLED')->update(['status' => 'cancelled']);
    }
};
