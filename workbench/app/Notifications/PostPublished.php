<?php

namespace App\Notifications;

use App\Models\Post;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A source you follow posted": database, mail (the log mailer) and web push. */
class PostPublished extends Notification
{
    public function __construct(public Post $post)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New in '.$this->post->source->name.': '.$this->post->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->post->excerpt ?? '')
            ->action('Read the post', url('/p/'.$this->post->slug))
            ->line('Tags: '.implode(', ', $this->post->tags ?? []));
    }

    public function toArray(object $notifiable): array
    {
        return ['post_id' => $this->post->id, 'title' => $this->post->title, 'source' => $this->post->source->name];
    }

    public function toWebPush(object $notifiable): array
    {
        return ['title' => $this->post->title, 'body' => $this->post->excerpt, 'url' => url('/p/'.$this->post->slug)];
    }
}
