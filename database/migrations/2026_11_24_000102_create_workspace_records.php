<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workspace_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->json('payload');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('updated_at');
            $table->index(['user_id', 'kind', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_records');
    }
};
