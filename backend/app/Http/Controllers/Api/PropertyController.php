<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PropertyController extends Controller
{
    #[OA\Get(
        path: '/properties',
        operationId: 'propertiesIndex',
        tags: ['Properties'],
        summary: 'List approved properties',
        description: 'Paginated catalogue of properties that have been approved by moderation. Public, no authentication required.',
        parameters: [
            new OA\Parameter(name: 'city', in: 'query', description: 'Partial match on the location.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['apartment', 'house', 'villa', 'studio', 'office'])),
            new OA\Parameter(name: 'rooms', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'min_price', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'max_price', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches title, location or description.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 12)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of properties.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Property')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function index(Request $request)
    {
        $query = Property::with(['owner', 'images'])
            ->where('status', 'approved');

        if ($request->filled('city')) {
            $query->where('location', 'like', '%' . $request->city . '%');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('rooms')) {
            $query->where('rooms', $request->rooms);
        }

        if ($request->filled('min_price')) {
            $query->where('price_per_month', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price_per_month', '<=', $request->max_price);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $properties = $query->latest()->paginate($request->get('per_page', 12));

        return response()->json($properties);
    }

    #[OA\Get(
        path: '/properties/{id}',
        operationId: 'propertiesShow',
        tags: ['Properties'],
        summary: 'Show an approved property',
        description: 'Returns a single approved property with its owner and images. Public.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The property.', content: new OA\JsonContent(ref: '#/components/schemas/Property')),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function show($id)
    {
        $property = Property::with(['owner', 'images'])
            ->where('status', 'approved')
            ->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json($property);
    }

    #[OA\Get(
        path: '/properties/search',
        operationId: 'propertiesSearch',
        tags: ['Properties'],
        summary: 'Advanced property search',
        description: 'Like the catalogue listing but with operating surface area filters. Public.',
        parameters: [
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['apartment', 'house', 'villa', 'studio', 'office'])),
            new OA\Parameter(name: 'rooms', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'min_price', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'max_price', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'min_area', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'max_area', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 12)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of matching properties.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Property')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function search(Request $request)
    {
        $query = Property::with(['owner', 'images'])
            ->where('status', 'approved');

        if ($request->filled('location')) {
            $query->where('location', 'like', '%' . $request->location . '%');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('rooms')) {
            $query->where('rooms', $request->rooms);
        }

        if ($request->filled('min_price')) {
            $query->where('price_per_month', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price_per_month', '<=', $request->max_price);
        }

        if ($request->filled('min_area')) {
            $query->where('area', '>=', $request->min_area);
        }

        if ($request->filled('max_area')) {
            $query->where('area', '<=', $request->max_area);
        }

        $properties = $query->latest()->paginate($request->get('per_page', 12));

        return response()->json($properties);
    }
}
