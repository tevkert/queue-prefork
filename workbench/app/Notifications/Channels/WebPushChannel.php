<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends a notification's toWebPush() payload to every push subscription of the notifiable with
 * minishlink/web-push (VAPID-signed, payload encrypted per subscription). The
 * subscriptions' endpoints are the HTTP stub.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $subscriptions = $notifiable->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], ['TTL' => 3600], 5);

        $payload = json_encode($notification->toWebPush($notifiable));

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => 'aes128gcm',
            ]), $payload);
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                throw new \RuntimeException('web push failed: '.$report->getReason());
            }
        }
    }
}
