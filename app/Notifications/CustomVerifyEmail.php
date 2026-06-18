<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

class CustomVerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        Log::debug('[CUSTOM-VERIFY-EMAIL] Notification instance created');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        Log::info("Verification URL generated for User: {$notifiable->getKey()}");

        return (new MailMessage)
            ->subject('I-verify ang iyong Email Address - FloodIntel')
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . '!')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email', $verificationUrl)
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Thank you, The FloodIntel Team');
    }
}
