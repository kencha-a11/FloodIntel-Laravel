<?php

namespace App\Realtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a broadcast to Supabase Realtime over its HTTP endpoint.
 *
 * One attempt with a bounded timeout, then a logged failure. The polling
 * substitute is the terminal outcome of a failed publish, so no retry queue is
 * introduced (fallback rule F2, D-66).
 */
final class SupabaseBroadcaster
{
    public function publish(ReportMessage $message): bool
    {
        $settings = RealtimeSettings::fromConfig();

        if (! $settings->publishable()) {
            return false;
        }

        $messages = array_map(
            fn (string $topic): array => [
                'topic' => $topic,
                'event' => $message->event,
                'payload' => $message->payload,
                'private' => true,
            ],
            $message->topics,
        );

        try {
            $response = Http::withHeaders([
                'apikey' => $settings->serviceRoleKey,
                'Authorization' => 'Bearer '.$settings->serviceRoleKey,
            ])
                ->acceptJson()
                ->timeout($settings->broadcastTimeout)
                ->post(
                    rtrim($settings->url, '/').'/realtime/v1/api/broadcast?private=true',
                    ['messages' => $messages],
                );
        } catch (ConnectionException $exception) {
            Log::warning('Supabase Realtime broadcast could not connect.', [
                'event' => $message->event,
                'report_id' => $message->payload['id'],
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Supabase Realtime broadcast was rejected.', [
                'event' => $message->event,
                'report_id' => $message->payload['id'],
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
