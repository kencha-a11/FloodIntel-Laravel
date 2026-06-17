<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Barangay extends Model
{
    protected $table = 'barangays';

    protected $fillable = ['ADM4_EN', 'geom'];

    public function scopeWithGeoJson($query)
    {
        return $query->select('*', DB::raw('ST_AsGeoJSON(geom) as geojson'));
    }
}
