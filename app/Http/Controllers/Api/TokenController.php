<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Token\CreateTokenRequest;
use App\Http\Resources\TokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenController extends Controller
{
    /**
     * List the authenticated user's API tokens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($request->user()->tokens()->get());
    }

    /**
     * Create a new API token for the authenticated user.
     */
    public function store(CreateTokenRequest $request): JsonResponse
    {
        $token = $request->user()->createToken(
            $request->validated('name'),
            $request->validated('abilities', ['*']),
        );

        return response()->json([
            'data' => new TokenResource($token->accessToken),
            'token' => $token->plainTextToken,
        ], 201);
    }

    /**
     * Revoke a specific API token owned by the authenticated user.
     */
    public function destroy(Request $request, string $token): JsonResponse
    {
        $personalAccessToken = $request->user()->tokens()->find($token);

        if ($personalAccessToken === null) {
            return response()->json(null, 404);
        }

        $personalAccessToken->delete();

        return response()->json(null, 204);
    }

    /**
     * Revoke all API tokens owned by the authenticated user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(null, 204);
    }
}
