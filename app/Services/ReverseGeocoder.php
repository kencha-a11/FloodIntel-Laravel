<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ReverseGeocoder
{
    private const CACHE_PREFIX = 'geocode:reverse:';

    private const SUCCESS_TTL_DAYS = 30;

    private const FAILURE_TTL_MINUTES = 10;

    /**
     * Address components in display order, most specific first.
     *
     * @var list<string>
     */
    private const ADDRESS_KEYS = [
        'house_number',
        'road',
        'neighbourhood',
        'quarter',
        'suburb',
        'village',
        'town',
        'city',
        'municipality',
        'county',
        'state',
        'postcode',
        'country',
    ];

    public function __construct(private readonly NominatimBudget $budget) {}

    /**
     * Resolve coordinates to a human-readable address.
     *
     * Failures are cached briefly and never thrown. A lookup that the shared
     * provider budget did not admit returns null without caching, because the
     * provider was never asked.
     */
    public function lookup(float $latitude, float $longitude): ?string
    {
        $key = self::CACHE_PREFIX.$this->cacheKey($latitude, $longitude);

        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('address', $cached)) {
            return is_string($cached['address']) ? $cached['address'] : null;
        }

        if (! $this->budget->reserve()) {
            return null;
        }

        $attempt = $this->fetch($latitude, $longitude);

        Cache::put(
            $key,
            ['address' => $attempt['address']],
            $this->cacheTtl($attempt['address'], $attempt['retry_after']),
        );

        return $attempt['address'];
    }

    private function cacheKey(float $latitude, float $longitude): string
    {
        return number_format($latitude, 5, '.', '')
            .':'
            .number_format($longitude, 5, '.', '');
    }

    /**
     * @return array{address: ?string, retry_after: ?int}
     */
    private function fetch(float $latitude, float $longitude): array
    {
        /** @var array{url: string, user_agent: string, key: string|null, timeout: int} $config */
        $config = config('services.geocoder');

        $query = [
            'format' => 'jsonv2',
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 18,
            'addressdetails' => 1,
            'accept-language' => 'en',
        ];

        if (is_string($config['key']) && $config['key'] !== '') {
            $query['key'] = $config['key'];
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $config['user_agent']])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout($config['timeout'])
                ->get(rtrim($config['url'], '/').'/reverse', $query);
        } catch (Throwable $exception) {
            Log::warning('Reverse geocoding request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return ['address' => null, 'retry_after' => null];
        }

        if (! $response->successful()) {
            Log::warning('Reverse geocoding provider returned an error.', [
                'status' => $response->status(),
            ]);

            return [
                'address' => null,
                'retry_after' => $this->retryAfterSeconds($response),
            ];
        }

        $payload = $response->json();

        return [
            'address' => is_array($payload) ? $this->formatAddress($payload) : null,
            'retry_after' => null,
        ];
    }

    private function cacheTtl(?string $address, ?int $retryAfter): \DateTimeInterface
    {
        if ($address !== null) {
            return now()->addDays(self::SUCCESS_TTL_DAYS);
        }

        return now()->addSeconds(max(self::FAILURE_TTL_MINUTES * 60, $retryAfter ?? 0));
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatAddress(array $payload): ?string
    {
        $components = $payload['address'] ?? null;

        if (is_array($components)) {
            $parts = [];

            foreach (self::ADDRESS_KEYS as $key) {
                $value = $components[$key] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $value = $this->normalize($value);

                if ($value === '' || in_array($value, $parts, true)) {
                    continue;
                }

                $parts[] = $value;
            }

            if ($parts !== []) {
                return Str::limit(implode(', ', $parts), 500, '');
            }
        }

        $displayName = $payload['display_name'] ?? null;

        if (! is_string($displayName)) {
            return null;
        }

        $displayName = $this->normalize($displayName);

        return $displayName === '' ? null : Str::limit($displayName, 500, '');
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
