<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipe_backups', function (Blueprint $table) {
            $table->unsignedInteger('total_cards')->default(0)->after('errors_count');
            $table->string('current_step', 50)->nullable()->after('total_cards');
        });
    }

    public function down(): void
    {
        Schema::table('pipe_backups', function (Blueprint $table) {
            $table->dropColumn(['total_cards', 'current_step']);
        });
    }
};
