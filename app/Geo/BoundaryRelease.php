<?php

namespace App\Geo;

final readonly class BoundaryRelease
{
    public function __construct(
        public BoundarySet $boundaries,
        public string $release,
    ) {}
}
