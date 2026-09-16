<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipe_backups', function (Blueprint $table) {
            $table->unsignedInteger('cards_count')->default(0)->after('pipe_name');
            $table->unsignedInteger('attachments_count')->default(0)->after('cards_count');
            $table->unsignedInteger('errors_count')->default(0)->after('attachments_count');
        });

        Schema::create('pipe_backup_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipe_backup_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedInteger('card_id')->nullable();
            $table->string('filename')->nullable();
            $table->text('message');
            $table->timestamps();

            $table->index('pipe_backup_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipe_backup_errors');

        Schema::table('pipe_backups', function (Blueprint $table) {
            $table->dropColumn(['cards_count', 'attachments_count', 'errors_count']);
        });
    }
};
