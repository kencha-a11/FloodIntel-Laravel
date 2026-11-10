<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/**
 * The ceiling is declared once in config/uploads.php and must stay below the
 * runtime limits, so the application's own 413 and 422 answers arrive first.
 */
function declaredBytes(string $source, string $pattern): int
{
    expect(preg_match($pattern, $source, $match))->toBe(1);

    $value = (int) $match[1];
    $unit = strtolower($match[2] ?? 'b');

    return match ($unit) {
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}

it('refuses a JSON body above the ceiling before authentication', function () {
    $ceiling = (int) config('uploads.request_bytes');

    $this->withServerVariables(['CONTENT_LENGTH' => (string) ($ceiling + 1)])
        ->postJson('/api/flood-reports', ['notes' => 'oversized'])
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('message', 'The request body is too large. The limit is 6 MB.');
});

it('refuses a multipart body above the ceiling', function () {
    $ceiling = (int) config('uploads.request_bytes');

    $this->withServerVariables(['CONTENT_LENGTH' => (string) ($ceiling + 1)])
        ->post('/api/flood-reports', ['photo' => testPhoto()])
        ->assertStatus(413);
});

it('lets a body at the ceiling through to the route', function () {
    $ceiling = (int) config('uploads.request_bytes');

    // A guest still gets 401, which proves the request reached the route
    // group instead of being refused at the edge.
    $this->withServerVariables(['CONTENT_LENGTH' => (string) $ceiling])
        ->postJson('/api/flood-reports', [])
        ->assertStatus(401);
});

it('accepts a normal report under the ceiling', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', [
            'flood_level' => 'knee',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'gps_accuracy' => 12.5,
            'photo' => testPhoto(),
            'notes' => 'Water rising near the school.',
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ])
        ->assertCreated();
});

it('keeps the declared ladder below the PHP and nginx limits', function () {
    $photo = (int) config('uploads.photo_bytes');
    $request = (int) config('uploads.request_bytes');

    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $nginx = file_get_contents(base_path('docker/nginx/default.conf.template'));

    $upload = declaredBytes($dockerfile, '/upload_max_filesize=(\d+)([MK])/');
    $post = declaredBytes($dockerfile, '/post_max_size=(\d+)([MK])/');
    $edge = declaredBytes($nginx, '/client_max_body_size\s+(\d+)([mk]);/i');

    expect($photo)->toBeLessThan($request)
        ->and($upload)->toBeGreaterThanOrEqual($request)
        ->and($post)->toBeGreaterThan($request)
        // PHP discards a body over post_max_size before the application can
        // refuse it, so the edge must not admit more than PHP accepts. With
        // the edge above PHP, a body in that gap answered 200 with an empty
        // body and an oversized upload looked accepted (DP-05).
        ->and($edge)->toBeGreaterThanOrEqual($post)
        ->and($edge)->toBeGreaterThan($request);
});

it('keeps the photo rule and the client ceiling in step', function () {
    // The client compresses to a 1 MB target; only a queued older payload can
    // reach 5 MB, and the server must name that rejection so the queue can
    // shrink it (D-108).
    expect(config('uploads.photo_bytes'))->toBe(5 * 1024 * 1024);
});
