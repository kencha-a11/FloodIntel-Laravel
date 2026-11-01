<?php

use App\Support\ImageSanitizer;
use App\Support\InvalidUploadedImage;

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function testPngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

function testWebpBytes(): string
{
    return base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
}

it('strips EXIF and comment segments from a JPEG while keeping the frame', function () {
    $app1 = "\xFF\xE1".pack('n', 2 + strlen("Exif\0\0GPSDATA"))."Exif\0\0GPSDATA";
    $comment = "\xFF\xFE".pack('n', 2 + strlen('author: jane')).'author: jane';
    $jpeg = "\xFF\xD8".$app1.$comment.substr(testJpegBytes(), 2);

    $result = ImageSanitizer::sanitize($jpeg);

    expect($result['extension'])->toBe('jpg')
        ->and($result['mime'])->toBe('image/jpeg')
        ->and($result['bytes'])->not->toContain('Exif')
        ->and($result['bytes'])->not->toContain('author: jane')
        ->and(strlen($result['bytes']))->toBeLessThan(strlen($jpeg));

    $path = tempnam(sys_get_temp_dir(), 'sanitized-');
    file_put_contents($path, $result['bytes']);
    $size = getimagesize($path);
    unlink($path);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(1)
        ->and($size[1])->toBe(1);
});

it('refuses a JPEG with bytes after its end marker', function () {
    ImageSanitizer::sanitize(testJpegBytes().'<?php echo 1;');
})->throws(InvalidUploadedImage::class);

it('refuses a truncated JPEG', function () {
    ImageSanitizer::sanitize(substr(testJpegBytes(), 0, 40));
})->throws(InvalidUploadedImage::class);

it('drops text chunks from a PNG and keeps its pixels', function () {
    $png = testPngBytes();
    $withText = substr($png, 0, 8)
        .pngChunk('IHDR', substr($png, 16, 13))
        .pngChunk('tEXt', "Author\0Jane")
        .substr($png, 8 + 25);

    $result = ImageSanitizer::sanitize($withText);

    expect($result['extension'])->toBe('png')
        ->and($result['bytes'])->not->toContain('tEXt')
        ->and($result['bytes'])->not->toContain('Jane')
        ->and($result['bytes'])->toBe($png);
});

it('refuses a PNG whose chunk checksum does not match', function () {
    $png = testPngBytes();
    $png[strlen($png) - 20] = chr(ord($png[strlen($png) - 20]) ^ 0xFF);

    ImageSanitizer::sanitize($png);
})->throws(InvalidUploadedImage::class);

it('refuses a PNG with bytes after its end chunk', function () {
    ImageSanitizer::sanitize(testPngBytes().'junk');
})->throws(InvalidUploadedImage::class);

it('drops the EXIF chunk from a WebP and repairs the declared size', function () {
    $webp = testWebpBytes();
    $data = "GPSDATA\0";
    $exif = 'EXIF'.pack('V', strlen($data)).$data;
    $withExif = 'RIFF'.pack('V', strlen($webp) - 8 + strlen($exif)).'WEBP'.substr($webp, 12).$exif;

    $result = ImageSanitizer::sanitize($withExif);

    expect($result['extension'])->toBe('webp')
        ->and($result['bytes'])->toBe($webp)
        ->and(unpack('V', substr($result['bytes'], 4, 4))[1] + 8)->toBe(strlen($result['bytes']));
});

it('refuses a WebP that declares a different size than it carries', function () {
    ImageSanitizer::sanitize(testWebpBytes().'x');
})->throws(InvalidUploadedImage::class);

it('refuses a file that is not an image at all', function () {
    ImageSanitizer::sanitize("<?php echo 'pwned';");
})->throws(InvalidUploadedImage::class);
