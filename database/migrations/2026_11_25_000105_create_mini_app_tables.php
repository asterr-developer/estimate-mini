<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mini_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('visitor', 100);
            $table->string('customer', 100);
            $table->foreignId('draft_id')->nullable()->constrained('workspace_records')->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items');
            $table->string('subject', 150);
            $table->timestamps();
            $table->index(['user_id', 'visitor']);
        });
        Schema::create('mini_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mini_request_id')->constrained()->cascadeOnDelete();
            $table->boolean('from_operator');
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_messages');
        Schema::dropIfExists('mini_requests');
    }
};
