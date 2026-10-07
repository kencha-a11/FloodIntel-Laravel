<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;

class LogLockout
{
    /**
     * Handle the lockout event.
     */
    public function handle(Lockout $event): void
    {
        Log::warning('Login lockout', [
            'login' => $event->request->input('login'),
            'ip' => $event->request->ip(),
        ]);
    }
}
