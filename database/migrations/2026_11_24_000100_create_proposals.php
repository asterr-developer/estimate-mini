<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 64)->index();
            $table->string('share_token', 64)->unique();
            $table->json('snapshot');
            $table->string('accepted_by', 100)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
