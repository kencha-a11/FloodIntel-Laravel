<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'ip_address' => data_get($this->resource, 'ip_address'),
            'user_agent' => data_get($this->resource, 'user_agent'),
            'logged_in_at' => data_get($this->resource, 'logged_in_at'),
            'last_used_at' => data_get($this->resource, 'last_used_at'),
            'is_current' => (bool) data_get($this->resource, 'is_current'),
        ];
    }
}
