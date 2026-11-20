<?php

namespace App\Http\Requests\Sitrep;

/**
 * Drafts are mutable until a snapshot freezes them (D-147); the same
 * contract and privacy boundary apply to an edit as to a creation.
 */
class UpdateSitrepSupplementRequest extends StoreSitrepSupplementRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['event_id']);

        return $rules;
    }
}
