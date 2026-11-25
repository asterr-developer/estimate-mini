<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('revision_of')->nullable()->constrained('proposals')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_of');
            $table->dropColumn('withdrawn_at');
        });
    }
};
