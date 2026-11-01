<?php

namespace App\Http\Resources;

use App\Enums\UserAbility;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One role and the abilities the server grants it (FR-105, D-114).
 *
 * Reads the enum so the published matrix cannot drift from the gates.
 *
 * @mixin UserRole
 */
class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserRole $role */
        $role = $this->resource;

        return [
            'value' => $role->value,
            'label' => $role->label(),
            'interface' => [
                'value' => $role->interface()->value,
                'label' => $role->interface()->label(),
            ],
            'barangay_scoped' => $role->isBarangayScoped(),
            'abilities' => array_map(
                fn (UserAbility $ability): array => [
                    'value' => $ability->value,
                    'label' => $ability->label(),
                ],
                $role->abilities(),
            ),
        ];
    }
}
