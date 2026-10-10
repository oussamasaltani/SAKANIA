<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Property;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with(['property', 'property.images', 'property.owner'])
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));

        return response()->json($favorites);
    }

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
