<?php

namespace App\Http\Resources;

use App\Enums\AccessTagType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One value of the closed taxonomy with the label an interface renders (FR-112, NFR-42).
 *
 * Reads the enum so the published vocabulary cannot drift from the validator.
 *
 * @mixin AccessTagType
 */
class AccessTagTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AccessTagType $type */
        $type = $this->resource;

        return [
            'value' => $type->value,
            'label' => $type->label(),
            'requires_note' => $type->requiresNote(),
        ];
    }
}
