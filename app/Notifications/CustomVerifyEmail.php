<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CustomVerifyEmail extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        Log::debug('[CUSTOM-VERIFY-EMAIL] Notification instance created');
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
        // 1. Gagawa muna ng orihinal na temporary signed backend URL si Laravel
        $backendUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // 2. Hihimayin natin ang query strings (?expires=...&signature=...) mula sa backend URL
        $queryString = parse_url($backendUrl, PHP_URL_QUERY);

        // 3. Kunin ang base path ng iyong React Frontend mula sa .env (.e.g., http://localhost:5173 o production URL)
        $frontendBaseUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        // 4. I-reconstruct ang URL para ituro ang user sa React Routing layout mo
        $id = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());
        $frontendVerificationUrl = "{$frontendBaseUrl}/email/verify/{$id}/{$hash}?{$queryString}";

        Log::info("Verification URL successfully routed to Frontend for User ID: {$id}");

        return (new MailMessage)
            ->subject('I-verify ang iyong Email Address - FloodIntel')
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . '!')
            ->line('Please click the button below to verify your email address and activate your FloodIntel account.')
            ->action('Verify Email', $frontendVerificationUrl)
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Thank you, The FloodIntel Team');
    }
}
