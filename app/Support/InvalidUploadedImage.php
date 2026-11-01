<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidUploadedImage extends RuntimeException implements ShouldntReport
{
    public static function forReason(string $reason): self
    {
        return new self(sprintf('The uploaded file is not a usable JPEG, PNG, or WebP image: %s.', $reason));
    }
}
