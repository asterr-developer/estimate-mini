<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->json('line_feedback')->nullable();
        });
        Schema::create('proposal_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('payment_index');
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->uuid('request_id')->unique();
            $table->timestamp('created_at');
            $table->timestamp('voided_at')->nullable();
            $table->index(['proposal_id', 'payment_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_receipts');
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn(['archived_at', 'line_feedback']));
    }
};
