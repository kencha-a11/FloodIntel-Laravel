<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FloodEventResource;
use App\Models\FloodEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FloodEventController extends Controller
{
    /**
     * Impact reports are filed against an event, so the capture screen needs
     * the events that are still accepting filings.
     */
    public function index(): AnonymousResourceCollection
    {
        return FloodEventResource::collection(
            FloodEvent::query()
                ->where('is_closed', false)
                ->latest('starts_at')
                ->get(),
        );
    }
}
