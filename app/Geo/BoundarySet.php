<?php

namespace App\Geo;

use InvalidArgumentException;

/**
 * The loaded barangay polygons, held as plain coordinate rings.
 *
 * This class is deliberately free of framework dependencies so containment
 * can be tested without booting Laravel.
 */
final class BoundarySet
{
    /**
     * Polygon rings keyed by barangay code, in ascending code order. Each
     * polygon is a list of rings: the first is the exterior, the rest are
     * holes. Even-odd crossing over all rings of a polygon handles holes.
     *
     * @var array<string, list<list<array{0: float, 1: float}>>>
     */
    private readonly array $polygons;

    /** @var array{0: float, 1: float, 2: float, 3: float} */
    private readonly array $extent;

    /**
     * @param  array<string, list<list<array{0: float, 1: float}>>>  $polygons
     */
    public function __construct(array $polygons)
    {
        if ($polygons === []) {
            throw new InvalidArgumentException('A boundary set needs at least one polygon.');
        }

        ksort($polygons, SORT_STRING);
        $this->polygons = $polygons;

        $minX = $minY = INF;
        $maxX = $maxY = -INF;

        foreach ($polygons as $rings) {
            foreach ($rings as $ring) {
                foreach ($ring as [$x, $y]) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        $this->extent = [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map('strval', array_keys($this->polygons));
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->polygons);
    }

    /**
     * The rings of one barangay, in artifact order. This release stores each
     * barangay as a single exterior ring, so a ring is also one polygon part.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function rings(string $code): array
    {
        return $this->polygons[$code] ?? [];
    }

    /**
     * The bounding box of every polygon, used only to tell an outside point
     * from a gap inside the mapped area.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function extent(): array
    {
        return $this->extent;
    }

    public function coversExtent(float $longitude, float $latitude): bool
    {
        [$minX, $minY, $maxX, $maxY] = $this->extent;

        return $longitude >= $minX && $longitude <= $maxX
            && $latitude >= $minY && $latitude <= $maxY;
    }

    /**
     * The code of the first polygon, in ascending code order, that contains
     * the point; null when no polygon does. Shared edges therefore resolve to
     * the lowest code every time.
     */
    public function locate(float $longitude, float $latitude): ?string
    {
        foreach ($this->polygons as $code => $rings) {
            if ($this->contains($rings, $longitude, $latitude)) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * @param  list<list<array{0: float, 1: float}>>  $rings
     */
    private function contains(array $rings, float $x, float $y): bool
    {
        $inside = false;

        foreach ($rings as $ring) {
            $count = count($ring);

            for ($index = 0, $previous = $count - 1; $index < $count; $previous = $index++) {
                [$x1, $y1] = $ring[$previous];
                [$x2, $y2] = $ring[$index];

                if ($this->onSegment($x, $y, $x1, $y1, $x2, $y2)) {
                    return true;
                }

                if (($y1 > $y) !== ($y2 > $y)) {
                    $intersection = ($x2 - $x1) * ($y - $y1) / ($y2 - $y1) + $x1;

                    if ($x < $intersection) {
                        $inside = ! $inside;
                    }
                }
            }
        }

        return $inside;
    }

    private function onSegment(float $x, float $y, float $x1, float $y1, float $x2, float $y2): bool
    {
        $cross = ($x2 - $x1) * ($y - $y1) - ($y2 - $y1) * ($x - $x1);

        if (abs($cross) > 1e-12) {
            return false;
        }

        return $x >= min($x1, $x2) - 1e-12 && $x <= max($x1, $x2) + 1e-12
            && $y >= min($y1, $y2) - 1e-12 && $y <= max($y1, $y2) + 1e-12;
    }
}
