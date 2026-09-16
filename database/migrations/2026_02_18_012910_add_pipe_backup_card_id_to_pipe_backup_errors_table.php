<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipe_backup_errors', function (Blueprint $table) {
            $table->foreignId('pipe_backup_card_id')
                ->nullable()
                ->after('pipe_backup_id')
                ->constrained('pipe_backup_cards')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pipe_backup_errors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipe_backup_card_id');
        });
    }
};
