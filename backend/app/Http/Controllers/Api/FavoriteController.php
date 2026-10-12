<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Property;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FavoriteController extends Controller
{
    #[OA\Get(
        path: '/favorites',
        operationId: 'favoritesIndex',
        tags: ['Favorites'],
        summary: 'List saved properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated favorites.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Favorite')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function index(Request $request)
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with(['property', 'property.images', 'property.owner'])
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));

        return response()->json($favorites);
    }

    #[OA\Post(
        path: '/favorites/{propertyId}',
        operationId: 'favoritesStore',
        tags: ['Favorites'],
        summary: 'Save a property',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'propertyId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Property added to favorites.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'favorite', ref: '#/components/schemas/Favorite'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, description: 'The property is already a favorite.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
        ],
    )]
    public function store(Request $request, $propertyId)
    {
        $property = Property::where('status', 'approved')->find($propertyId);
        if (!$property) {
            return response()->json(['message' => 'Property not found or not available.'], 404);
        }

        $existing = Favorite::where('user_id', $request->user()->id)
            ->where('property_id', $propertyId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Property is already in favorites.'], 422);
        }

        $favorite = Favorite::create([
            'user_id' => $request->user()->id,
            'property_id' => $propertyId,
        ]);

        return response()->json([
            'message' => 'Property added to favorites.',
            'favorite' => $favorite->load(['property', 'property.images']),
        ], 201);
    }

    #[OA\Delete(
        path: '/favorites/{propertyId}',
        operationId: 'favoritesDestroy',
        tags: ['Favorites'],
        summary: 'Remove a property from favorites',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'propertyId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Property removed from favorites.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function destroy(Request $request, $propertyId)
    {
        $favorite = Favorite::where('user_id', $request->user()->id)
            ->where('property_id', $propertyId)
            ->first();

        if (!$favorite) {
            return response()->json(['message' => 'Favorite not found.'], 404);
        }

        $favorite->delete();

        return response()->json(['message' => 'Property removed from favorites.']);
    }
}
