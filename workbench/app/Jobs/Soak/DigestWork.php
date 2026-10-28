<?php

namespace App\Jobs\Soak;

use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * A digest burst member: renders one user's digest e-mail (markdown mail components) over
 * $size / 20 recent posts, behind a Pennant feature (database driver), and caches it.
 */
class DigestWork extends SoakJob
{
    public function handle(): void
    {
        $user = User::query()->find(1 + $this->seed % 50);

        if ($user === null) {
            return;
        }

        $posts = Post::query()->with('source')->latest('published_at')->limit(max(5, intdiv($this->size, 20)))->get();
        $version = Feature::for($user)->active('digest-v2') ? 'v2' : 'v1';

        $mail = (new MailMessage)
            ->subject("Your {$version} digest")
            ->markdown('mail.digest', ['user' => $user, 'posts' => $posts, 'version' => $version]);

        Cache::put("digest:{$user->id}", (string) $mail->render(), 600);
    }
}
