<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipe_backup_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipe_backup_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('card_id');
            $table->string('card_title');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attachments_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('pipe_backup_id');
            $table->index(['pipe_backup_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipe_backup_cards');
    }
};
