<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class CustomVerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Bumuo ng temporary signed URL para sa email verification
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify', // Ito ang pangalan ng route sa routes/api.php
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // I-redirect ang user sa frontend verification handler (same tab)
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $finalUrl = $frontendUrl . '/verified-email?verify_url=' . urlencode($verificationUrl);

        // Gamitin ang iyong umiiral na Blade template: resources/views/emails/verify.blade.php
        return (new MailMessage)
            ->subject('I-verify ang iyong Email Address - FloodIntel')
            ->view('emails.verify', ['url' => $finalUrl, 'userName' => $notifiable->name]);
    }
}