<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BarangayController extends Controller
{
    private $apalitBarangays = [
        'Balucuc',
        'Calantipe',
        'Cansinala',
        'Capalangan',
        'Colgante',
        'Paligui',
        'Sampaloc',
        'San Juan (Pob.)',
        'San Vicente',
        'Tabuyuc (Santo Rosario)',
        'Sulipan',
        'Sucad'
    ];

    public function getBarangayMap()
    {
        try {
            // Sabihan ang database kung nasaan ang PostGIS functions
            DB::statement('SET search_path TO public, extensions');

            $features = DB::table('barangays') // Updated table name
                ->select(
                    'id',
                    // Ginamitan natin ng double quotes ang ADM4_EN para iwas error sa Postgres case-sensitivity
                    DB::raw('"ADM4_EN" as name'),
                    DB::raw('ST_AsGeoJSON(geom) as geometry')
                )
                ->get();

            $geojson = [
                'type' => 'FeatureCollection',
                'features' => $features->map(function ($item) {
                    return [
                        'type' => 'Feature',
                        'properties' => [
                            'id' => $item->id,
                            'name' => $item->name,
                        ],
                        'geometry' => json_decode($item->geometry)
                    ];
                })
            ];

            return response()->json($geojson);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Map Data Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getFloodSimulation(Request $request)
    {
        $waterLevel = (float) $request->query('level', 0);

        try {
            DB::statement('SET search_path TO public, extensions');

            $results = DB::table('barangays')
                ->select([
                    'id',
                    DB::raw('"ADM4_EN" as name'),
                    DB::raw('ST_AsGeoJSON(geom) as geometry'),
                    DB::raw("(
                        SELECT AVG(e.height)
                        FROM elevations e
                        WHERE ST_Contains(
                            geom,
                            ST_SetSRID(ST_Point(e.longitude, e.latitude), 4326)
                        )
                    ) as avg_height")
                ])
                ->whereIn('ADM4_EN', $this->apalitBarangays)
                ->get();

            $geojson = [
                'type' => 'FeatureCollection',
                'features' => $results->map(function ($item) {
                    return [
                        'type' => 'Feature',
                        'properties' => [
                            'id' => $item->id,
                            'name' => $item->name,
                            'avg_elevation' => round($item->avg_height ?? 0, 2),
                        ],
                        'geometry' => json_decode($item->geometry)
                    ];
                })
            ];

            return response()->json($geojson);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getSpecificFloodPoints(Request $request)
    {
        $waterLevel = (float) $request->query('level', 0);

        try {
            DB::statement('SET search_path TO public, extensions');

            /* TECHNIQUE: SQL JITTERING
               Dinodoble natin ang points sa database query pero ini-usog natin
               ng konti (0.00015 degrees) para mapuno ang grid gaps.
            */
            $barList = "'" . implode("','", $this->apalitBarangays) . "'";

            $query = "
                SELECT e.longitude, e.latitude, e.height FROM elevations e
                INNER JOIN barangays b ON ST_Contains(b.geom, ST_SetSRID(ST_Point(e.longitude, e.latitude), 4326))
                WHERE e.height <= ? AND b.\"ADM4_EN\" IN ($barList)

                UNION ALL

                SELECT e.longitude + 0.00015, e.latitude + 0.00015, e.height FROM elevations e
                INNER JOIN barangays b ON ST_Contains(b.geom, ST_SetSRID(ST_Point(e.longitude, e.latitude), 4326))
                WHERE e.height <= ? AND b.\"ADM4_EN\" IN ($barList)

                UNION ALL

                SELECT e.longitude - 0.00015, e.latitude - 0.00015, e.height FROM elevations e
                INNER JOIN barangays b ON ST_Contains(b.geom, ST_SetSRID(ST_Point(e.longitude, e.latitude), 4326))
                WHERE e.height <= ? AND b.\"ADM4_EN\" IN ($barList)
            ";

            $points = DB::select($query, [$waterLevel, $waterLevel, $waterLevel]);

            return response()->json([
                'status' => 'success',
                'point_count' => count($points),
                'data' => $points
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function saveSimulation(Request $request)
    {
        $validated = $request->validate([
            'scenario_name' => 'required|string',
            'water_level' => 'required|numeric',
            'description' => 'nullable|string'
        ]);

        $id = DB::table('public.flood_simulations')->insertGetId([
            'scenario_name' => $validated['scenario_name'],
            'water_level' => $validated['water_level'],
            'description' => $validated['description'],
            'created_at' => now()
        ]);

        return response()->json(['message' => 'Simulation saved!', 'id' => $id]);
    }

    public function getBalucucData()
    {
        try {
            DB::statement('SET search_path TO public, extensions');

            $data = DB::table('barangays as b')
                ->select([
                    'b.id',
                    DB::raw('b."ADM4_EN" as name'),
                    DB::raw("(
                    SELECT AVG(e.height)
                    FROM elevations e
                    WHERE ST_Contains(
                        b.geom,
                        ST_SetSRID(ST_Point(e.longitude, e.latitude), 4326)
                    )
                ) as avg_elevation")
                ])
                // HUWAG gumamit ng DB::raw dito para iwas triple quotes
                // Ang Laravel na ang bahala mag-quote sa column name at value
                ->where('ADM4_EN', '=', 'Balucuc')
                ->first();

            if (!$data) {
                return response()->json(['error' => 'Barangay Balucuc not found'], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'barangay' => $data->name,
                    'elevation_m' => round($data->avg_elevation, 2),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
