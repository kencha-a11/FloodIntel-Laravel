<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloodSimulation extends Model
{
    protected $table = 'flood_simulations';

    protected $fillable = ['scenario_name', 'water_level', 'description'];

    // in supabase id, scenrio_name, created_at only
}
