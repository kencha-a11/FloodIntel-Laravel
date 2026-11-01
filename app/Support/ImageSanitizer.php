<?php

namespace App\Support;

/**
 * Rebuilds an uploaded image so the stored file is never the received file (API-08).
 *
 * PNG and WebP are rewritten chunk by chunk and JPEG segment by segment: every
 * metadata carrier (EXIF, XMP, IPTC, comments, text chunks) is dropped, every
 * segment is validated against its own declared length or checksum, and any byte
 * after the declared end is refused. The output is a fresh container that the
 * browser receives with a random name and a server-chosen extension.
 *
 * This is a container rewrite, not a pixel decode: PHP here has neither GD nor
 * Imagick, so a re-encode that would also normalise the entropy stream is not
 * available. The residual is recorded on API-08; the strip, the structural refusal,
 * and the random name are all enforced here.
 */
final class ImageSanitizer
{
    /**
     * @return array{bytes: string, extension: string, mime: string}
     */
    public static function sanitize(string $bytes): array
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => throw InvalidUploadedImage::forReason('the file signature is not JPEG, PNG, or WebP'),
        };
    }

    private static function jpeg(string $bytes): array
    {
        $length = strlen($bytes);
        $offset = 2;
        $out = "\xFF\xD8";
        $frameSeen = false;
        $scanSeen = false;

        while (true) {
            if ($offset + 1 >= $length || $bytes[$offset] !== "\xFF") {
                throw InvalidUploadedImage::forReason('the JPEG segment stream ended early');
            }

            while ($offset < $length && $bytes[$offset] === "\xFF") {
                $offset++;
            }

            if ($offset >= $length) {
                throw InvalidUploadedImage::forReason('the JPEG ended before its frame data');
            }

            $marker = ord($bytes[$offset]);
            $offset++;

            if ($marker === 0xD9) {
                $out .= "\xFF\xD9";

                break;
            }

            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF".chr($marker);

                continue;
            }

            if ($offset + 2 > $length) {
                throw InvalidUploadedImage::forReason('a JPEG segment length is missing');
            }

            $segmentLength = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw InvalidUploadedImage::forReason('a JPEG segment runs past the end of the file');
            }

            $payload = substr($bytes, $offset + 2, $segmentLength - 2);
            $offset += $segmentLength;

            if (($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE) {
                // APPn (EXIF, XMP, IPTC) and COM carry metadata; none is needed to decode.
                continue;
            }

            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                if (strlen($payload) < 6) {
                    throw InvalidUploadedImage::forReason('the JPEG frame header is truncated');
                }

                $height = (ord($payload[1]) << 8) | ord($payload[2]);
                $width = (ord($payload[3]) << 8) | ord($payload[4]);
                if ($width === 0 || $height === 0) {
                    throw InvalidUploadedImage::forReason('the JPEG frame declares no pixels');
                }

                $frameSeen = true;
            }

            $out .= "\xFF".chr($marker).pack('n', $segmentLength).$payload;

            if ($marker === 0xDA) {
                $end = strpos($bytes, "\xFF\xD9", $offset);
                if ($end === false || $end !== $length - 2) {
                    throw InvalidUploadedImage::forReason('the JPEG carries data after its end marker');
                }

                $out .= substr($bytes, $offset);
                $scanSeen = true;

                break;
            }
        }

        if (! $frameSeen || ! $scanSeen) {
            throw InvalidUploadedImage::forReason('the JPEG has no frame or no scan data');
        }

        return ['bytes' => $out, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
    }

    private static function png(string $bytes): array
    {
        $length = strlen($bytes);
        $offset = 8;
        $out = substr($bytes, 0, 8);
        $headerSeen = false;
        $dataSeen = false;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);

            if ($offset + 12 + $chunkLength > $length) {
                throw InvalidUploadedImage::forReason('a PNG chunk runs past the end of the file');
            }

            $data = substr($bytes, $offset + 8, $chunkLength);
            $recorded = unpack('N', substr($bytes, $offset + 8 + $chunkLength, 4))[1];
            if (($recorded & 0xFFFFFFFF) !== (crc32($type.$data) & 0xFFFFFFFF)) {
                throw InvalidUploadedImage::forReason('a PNG chunk fails its checksum');
            }

            $offset += 12 + $chunkLength;

            if (! $headerSeen && $type !== 'IHDR') {
                throw InvalidUploadedImage::forReason('the PNG does not start with its header chunk');
            }

            $kept = in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS'], true);
            if (! $kept && ctype_upper($type[0])) {
                throw InvalidUploadedImage::forReason('the PNG carries an unknown critical chunk');
            }

            if ($type === 'IHDR') {
                if ($chunkLength < 8) {
                    throw InvalidUploadedImage::forReason('the PNG header chunk is truncated');
                }

                $width = unpack('N', substr($data, 0, 4))[1];
                $height = unpack('N', substr($data, 4, 4))[1];
                if ($width === 0 || $height === 0) {
                    throw InvalidUploadedImage::forReason('the PNG declares no pixels');
                }

                $headerSeen = true;
            }

            if ($type === 'IDAT') {
                $dataSeen = true;
            }

            if ($kept) {
                $out .= pack('N', $chunkLength).$type.$data.pack('N', $recorded);
            }

            if ($type === 'IEND') {
                if ($offset !== $length) {
                    throw InvalidUploadedImage::forReason('the PNG carries data after its end chunk');
                }

                break;
            }
        }

        if (! $headerSeen || ! $dataSeen || $offset !== $length) {
            throw InvalidUploadedImage::forReason('the PNG is truncated or has no pixel data');
        }

        return ['bytes' => $out, 'extension' => 'png', 'mime' => 'image/png'];
    }

    private static function webp(string $bytes): array
    {
        $length = strlen($bytes);
        $declared = unpack('V', substr($bytes, 4, 4))[1];
        if ($declared + 8 !== $length) {
            throw InvalidUploadedImage::forReason('the WebP declares a different size than it carries');
        }

        $offset = 12;
        $payload = '';
        $imageSeen = false;

        while ($offset + 8 <= $length) {
            $fourcc = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];

            if ($offset + 8 + $size > $length) {
                throw InvalidUploadedImage::forReason('a WebP chunk runs past the end of the file');
            }

            $data = substr($bytes, $offset + 8, $size);
            $offset += 8 + $size + ($size % 2);

            if (in_array($fourcc, ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF'], true)) {
                $payload .= $fourcc.pack('V', $size).$data.($size % 2 ? "\0" : '');
                $imageSeen = $imageSeen || ($fourcc !== 'VP8X' && $fourcc !== 'ANIM');
            }
        }

        if ($offset !== $length) {
            throw InvalidUploadedImage::forReason('the WebP carries data after its last chunk');
        }

        if (! $imageSeen) {
            throw InvalidUploadedImage::forReason('the WebP has no image payload');
        }

        return ['bytes' => 'RIFF'.pack('V', strlen($payload) + 4).'WEBP'.$payload, 'extension' => 'webp', 'mime' => 'image/webp'];
    }
}
