<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    /**
     * Publish the role-and-ability matrix.
     *
     * Read-only: the catalogue is closed (FR-105), so a grant change is a
     * recorded decision rather than a request here (D-114).
     */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(collect(UserRole::cases()));
    }
}
