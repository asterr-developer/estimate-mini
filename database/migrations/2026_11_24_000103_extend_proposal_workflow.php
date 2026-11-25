<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('client_record_id')->nullable();
            $table->text('revision_comment')->nullable();
            $table->timestamp('revision_requested_at')->nullable();
            $table->index(['owner', 'client_record_id']);
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['owner', 'client_record_id']);
            $table->dropColumn(['client_record_id', 'revision_comment', 'revision_requested_at']);
        });
    }
};
