<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Elevation extends Model
{
    protected $table = 'elevations';

    // I-match ang columns na inayos natin kanina
    protected $fillable = ['longitude', 'latitude', 'height'];
}
