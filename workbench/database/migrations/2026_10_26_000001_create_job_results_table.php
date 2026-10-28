<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per fixture-job outcome (App\Support\JobResult).
        Schema::create('job_results', function (Blueprint $table) {
            $table->id();
            $table->string('job', 100)->index();
            $table->unsignedInteger('pid');
            $table->text('argv')->nullable();
            $table->longText('value')->nullable();
            $table->timestamp('created_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_results');
    }
};
