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
        Schema::create('publish_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('change_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->index();
            $table->string('state')->default('queued')->index();
            $table->string('stage')->nullable();
            $table->uuid('correlation_id')->unique();
            $table->string('weblate_task_url')->nullable();
            $table->string('gitlab_pipeline_url')->nullable();
            $table->string('github_commit_url')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publish_runs');
    }
};
