<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * List all user accounts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('barangay')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('barangay_id'), fn ($query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    /**
     * Create a new user account.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only([
            'first_name', 'last_name', 'email', 'contact_number', 'barangay_id',
        ]));

        $user->password = $request->string('password')->toString();
        $user->role = UserRole::from($request->string('role')->toString());
        $user->save();

        event(new Registered($user));

        return (new UserResource($user->load('barangay')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing user account.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->fill($request->safe()->only([
            'first_name', 'last_name', 'email', 'contact_number', 'barangay_id',
        ]));

        if ($request->filled('role')) {
            $user->role = UserRole::from($request->string('role')->toString());
        }

        $user->save();

        return new UserResource($user->load('barangay'));
    }

    /**
     * Delete a user account.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(null, 204);
    }
}
