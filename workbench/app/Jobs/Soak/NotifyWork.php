<?php

namespace App\Jobs\Soak;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostPublished;

/** The notify pool: $size notifications (database + log mailer + web push to the stub) to seeded users. */
class NotifyWork extends SoakJob
{
    public function handle(): void
    {
        $random = $this->random();

        for ($i = 0; $i < $this->size; $i++) {
            $user = User::query()->find($random->getInt(1, 50));
            $post = Post::query()->with('source')->orderBy('id')->skip($random->getInt(0, 200))->first()
                ?? Post::query()->with('source')->first();

            if ($user && $post) {
                $user->notify(new PostPublished($post));
            }
        }
    }
}
