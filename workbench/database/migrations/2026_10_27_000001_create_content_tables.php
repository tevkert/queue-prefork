<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A content model for the soak: sources imported from the stub, their posts,
     * media renditions, and web-push subscriptions for the notify queue.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('url');
            $table->string('feed_url');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('external_id');
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->json('tags')->nullable();
            $table->json('links')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->boolean('is_sensitive')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path')->unique();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('mime', 50);
            $table->string('blurhash', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 500);
            $table->string('public_key');
            $table->string('auth_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('sources');
    }
};
