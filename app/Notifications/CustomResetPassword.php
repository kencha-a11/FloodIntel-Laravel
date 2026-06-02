<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public string $token;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
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
        // Build the link to your React frontend reset password page
        // Example: http://localhost:5173/reset-password?token=...&email=user@example.com
        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset Password Notification - FloodIntel')
            ->view('emails.password-reset', ['url' => $resetUrl, 'userName' => $notifiable->name]);
    }
}
