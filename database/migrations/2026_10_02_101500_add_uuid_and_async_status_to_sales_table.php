<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'uuid')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $table = DB::getTablePrefix().'sales';

            DB::statement("ALTER TABLE `{$table}` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'PENDING'");
        }

        if ($driver === 'sqlite') {
            Schema::table('sales', function (Blueprint $table): void {
                $table->string('status', 32)->default('PENDING')->change();
            });
        }

        DB::table('sales')->where('status', 'completed')->update(['status' => 'COMPLETED']);
        DB::table('sales')->where('status', 'cancelled')->update(['status' => 'CANCELLED']);

        DB::table('sales')
            ->whereNull('uuid')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $sale): void {
                DB::table('sales')->where('id', $sale->id)->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('sales')->where('status', 'COMPLETED')->update(['status' => 'completed']);
        DB::table('sales')->where('status', 'CANCELLED')->update(['status' => 'cancelled']);
        DB::table('sales')->whereIn('status', ['PENDING', 'FAILED'])->update(['status' => 'completed']);

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $table = DB::getTablePrefix().'sales';

            DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed'");
        }

        if (Schema::hasColumn('sales', 'uuid')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
