<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows the soak's EloquentWork job upserts, reads and updates; keys are bounded (1..5000).
        Schema::create('soak_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('key')->unique();
            $table->string('name');
            $table->unsignedInteger('score')->index();
            $table->unsignedInteger('hits')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soak_items');
    }
};
