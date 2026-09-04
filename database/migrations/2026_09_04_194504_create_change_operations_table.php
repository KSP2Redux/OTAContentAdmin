<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('change_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('change_set_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->index();
            $table->string('action');
            $table->string('path');
            $table->string('original_sha', 64)->nullable();
            $table->string('payload_path')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('order_position')->nullable();
            $table->timestamps();
            $table->unique(['change_set_id', 'channel', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_operations');
    }
};
