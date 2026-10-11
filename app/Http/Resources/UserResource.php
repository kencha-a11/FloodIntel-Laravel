<?php

namespace App\Http\Resources;

use App\Enums\UserAbility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'interface' => $this->role->interface()->value,
            'abilities' => array_map(
                fn (UserAbility $ability): string => $ability->value,
                $this->role->abilities(),
            ),
            'barangay' => $this->whenLoaded(
                'barangay',
                fn () => $this->barangay === null ? null : new BarangayResource($this->barangay),
            ),
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
        ];
    }
}
