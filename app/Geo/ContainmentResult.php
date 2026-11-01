<?php

namespace App\Geo;

/**
 * Where a coordinate falls relative to the served barangay boundaries.
 *
 * Comparing this to a reporter's barangay is the caller's concern; this type
 * only answers where the point is and why it could not be answered.
 */
final readonly class ContainmentResult
{
    public const string INSIDE = 'inside';

    public const string OUTSIDE = 'outside';

    public const string UNKNOWN = 'unknown';

    public const string REASON_COVERAGE_GAP = 'coverage-gap';

    public const string REASON_ARTIFACT_UNAVAILABLE = 'artifact-unavailable';

    public const string REASON_BARANGAY_UNCOVERED = 'barangay-uncovered';

    public const string REASON_INVALID_COORDINATE = 'invalid-coordinate';

    private function __construct(
        public string $status,
        public ?string $code,
        public ?string $release,
        public ?string $reason,
    ) {}

    public static function inside(string $code, string $release): self
    {
        return new self(self::INSIDE, $code, $release, null);
    }

    public static function outside(string $release): self
    {
        return new self(self::OUTSIDE, null, $release, null);
    }

    public static function unknown(string $reason, ?string $release = null): self
    {
        return new self(self::UNKNOWN, null, $release, $reason);
    }
}
