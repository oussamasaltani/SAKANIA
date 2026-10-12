<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\Reservation;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;

class OwnerController extends Controller
{
    #[OA\Get(
        path: '/owner/dashboard',
        operationId: 'ownerDashboard',
        tags: ['Owner'],
        summary: 'Owner dashboard',
        description: 'Counters, the five most recent reservations and the four most recent payments for the owner\'s portfolio. Owner role only.',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard payload.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_properties', type: 'integer'),
                                new OA\Property(property: 'pending_reservations', type: 'integer'),
                                new OA\Property(property: 'active_tenants', type: 'integer'),
                                new OA\Property(property: 'total_revenue', type: 'number', format: 'float'),
                            ],
                        ),
                        new OA\Property(property: 'recent_reservations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reservation')),
                        new OA\Property(property: 'recent_payments', type: 'array', items: new OA\Items(ref: '#/components/schemas/Payment')),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function dashboard(Request $request)
    {
        $owner = $request->user();
        $ownerId = $owner->id;

        $stats = Cache::remember("owner_dashboard_{$ownerId}", 60, function () use ($ownerId) {
            $row = DB::selectOne('SELECT
                (SELECT COUNT(*) FROM properties WHERE owner_id = ?) as total_properties,
                (SELECT COUNT(*) FROM reservations r
                    JOIN properties p ON r.property_id = p.id
                    WHERE p.owner_id = ? AND r.status = "pending") as pending_reservations,
                (SELECT COUNT(DISTINCT c.tenant_id) FROM contracts c
                    WHERE c.owner_id = ? AND c.status = "active") as active_tenants,
                (SELECT COALESCE(SUM(py.paid_amount), 0) FROM payments py
                    JOIN reservations r ON py.reservation_id = r.id
                    JOIN properties p ON r.property_id = p.id
                    WHERE p.owner_id = ? AND py.status = "paid") as total_revenue
            ', [$ownerId, $ownerId, $ownerId, $ownerId]);

            return [
                'total_properties' => $row->total_properties,
                'pending_reservations' => $row->pending_reservations,
                'active_tenants' => $row->active_tenants,
                'total_revenue' => $row->total_revenue,
            ];
        });

        $recentReservations = Reservation::whereIn(
            'property_id',
            Property::where('owner_id', $ownerId)->pluck('id')
        )
            ->with(['property', 'property.images', 'tenant'])
            ->latest()
            ->take(5)
            ->get();

        // Scoped exactly like payments() below: a payment belongs to an
        // owner only through the property on its reservation.
        $recentPayments = Payment::whereHas('reservation.property', function ($q) use ($ownerId) {
            $q->where('owner_id', $ownerId);
        })
            ->with(['reservation', 'property', 'tenant'])
            ->latest('payment_date')
            ->take(4)
            ->get();

        return response()->json([
            'stats' => $stats,
            'recent_reservations' => $recentReservations,
            'recent_payments' => $recentPayments,
        ]);
    }

    #[OA\Get(
        path: '/owner/properties',
        operationId: 'ownerProperties',
        tags: ['Owner'],
        summary: 'List the owner\'s properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches title or location.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 12)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated properties.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Property')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function properties(Request $request)
    {
        $query = Property::where('owner_id', $request->user()->id)->with('images');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $properties = $query->latest()->paginate($request->get('per_page', 12));

        return response()->json($properties);
    }

    #[OA\Get(
        path: '/owner/properties/stats',
        operationId: 'ownerPropertyStats',
        tags: ['Owner'],
        summary: 'Owner property statistics',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Property stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'approved', type: 'integer'),
                        new OA\Property(property: 'pending', type: 'integer'),
                        new OA\Property(property: 'rejected', type: 'integer'),
                        new OA\Property(property: 'total_revenue', type: 'number', format: 'float'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function propertyStats(Request $request)
    {
        $ownerId = $request->user()->id;

        $stats = Cache::remember("owner_property_stats_{$ownerId}", 120, function () use ($ownerId) {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected,
                (SELECT COALESCE(SUM(py.paid_amount), 0) FROM payments py
                    JOIN reservations r ON py.reservation_id = r.id
                    JOIN properties p ON r.property_id = p.id
                    WHERE p.owner_id = ?) as total_revenue
            FROM properties WHERE owner_id = ?', [$ownerId, $ownerId]);

            return [
                'total' => $row->total,
                'approved' => $row->approved,
                'pending' => $row->pending,
                'rejected' => $row->rejected,
                'total_revenue' => $row->total_revenue,
            ];
        });

        return response()->json($stats);
    }

    #[OA\Post(
        path: '/owner/properties',
        operationId: 'ownerStoreProperty',
        tags: ['Owner'],
        summary: 'Create a property',
        description: 'The new property starts in `pending` status until an administrator approves it. Photos are sent as an array of base64 data URIs (max 10).',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'description', 'location', 'type', 'area', 'price_per_month', 'rooms', 'bathrooms', 'floor', 'capacity'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'location', type: 'string', maxLength: 255),
                    new OA\Property(property: 'type', type: 'string', enum: ['apartment', 'house', 'villa', 'studio', 'office']),
                    new OA\Property(property: 'area', type: 'number', minimum: 0),
                    new OA\Property(property: 'price_per_month', type: 'number', minimum: 0),
                    new OA\Property(property: 'rooms', type: 'integer', minimum: 0),
                    new OA\Property(property: 'bathrooms', type: 'integer', minimum: 0),
                    new OA\Property(property: 'floor', type: 'integer', minimum: 0),
                    new OA\Property(property: 'capacity', type: 'integer', minimum: 1),
                    new OA\Property(property: 'amenities', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    new OA\Property(property: 'images', type: 'array', maxItems: 10, items: new OA\Items(type: 'string'), description: 'Base64 data URIs.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Property created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'rejected_images', type: 'integer'),
                        new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function storeProperty(Request $request)
    {
        if (is_string($request->amenities)) {
            $request->merge(['amenities' => json_decode($request->amenities, true) ?? []]);
        }
        if (is_string($request->images)) {
            $request->merge(['images' => json_decode($request->images, true) ?? []]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'location' => 'required|string|max:255',
            'type' => 'required|string|in:apartment,house,villa,studio,office',
            'area' => 'required|numeric|min:0',
            'price_per_month' => 'required|numeric|min:0',
            'rooms' => 'required|integer|min:0',
            'bathrooms' => 'required|integer|min:0',
            'floor' => 'required|integer|min:0',
            'capacity' => 'required|integer|min:1',
            'amenities' => 'sometimes|array',
            'images' => 'sometimes|array|max:10',
            'images.*' => 'string',
        ]);

        DB::beginTransaction();
        $writtenPaths = [];

        try {
            $property = Property::create([
                'owner_id' => $request->user()->id,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'location' => $validated['location'],
                'type' => $validated['type'],
                'area' => $validated['area'],
                'price_per_month' => $validated['price_per_month'],
                'rooms' => $validated['rooms'],
                'bathrooms' => $validated['bathrooms'],
                'floor' => $validated['floor'],
                'capacity' => $validated['capacity'],
                'amenities' => $validated['amenities'] ?? [],
                'status' => 'pending',
            ]);

            $rejected = 0;

            if (!empty($validated['images'])) {
                foreach ($validated['images'] as $base64) {
                    $imageUrl = $this->saveBase64Image($base64);
                    if (!$imageUrl) {
                        // Counting the drop lets the UI say what happened instead of
                        // quietly saving a property with no photos at all.
                        $rejected++;
                        continue;
                    }
                    $writtenPaths[] = $imageUrl;
                    PropertyImage::create([
                        'property_id' => $property->id,
                        'image_url' => $imageUrl,
                    ]);
                }
            }

            DB::commit();

            $message = 'Property created successfully.';

            if ($rejected > 0) {
                $message .= ' ' . $this->rejectedImageMessage($rejected);
            }

            return response()->json([
                'message' => $message,
                'rejected_images' => $rejected,
                'property' => $property->load('images'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            // The rollback drops the database rows but not the files that were
            // already written to public/, which would leak orphans on every
            // failed request.
            foreach ($writtenPaths as $path) {
                app(ImageUploadService::class)->deleteStored($path, 'uploads/properties');
            }
            return response()->json(['message' => 'Failed to create property.'], 500);
        }
    }

    /**
     * Explains why photos were dropped so the owner is not left guessing.
     */
    private function rejectedImageMessage(int $count): string
    {
        return $count === 1
            ? '1 photo could not be saved and was skipped.'
            : $count . ' photos could not be saved and were skipped.';
    }

    #[OA\Put(
        path: '/owner/properties/{id}',
        operationId: 'ownerUpdateProperty',
        tags: ['Owner'],
        summary: 'Update a property',
        description: 'Only fields present are written. New photos are appended as base64 data URIs; existing ones are removed through `removed_images`.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'location', type: 'string', maxLength: 255),
                    new OA\Property(property: 'type', type: 'string', enum: ['apartment', 'house', 'villa', 'studio', 'office']),
                    new OA\Property(property: 'area', type: 'number', minimum: 0),
                    new OA\Property(property: 'price_per_month', type: 'number', minimum: 0),
                    new OA\Property(property: 'rooms', type: 'integer', minimum: 0),
                    new OA\Property(property: 'bathrooms', type: 'integer', minimum: 0),
                    new OA\Property(property: 'floor', type: 'integer', minimum: 0),
                    new OA\Property(property: 'capacity', type: 'integer', minimum: 1),
                    new OA\Property(property: 'amenities', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    new OA\Property(property: 'images', type: 'array', maxItems: 10, items: new OA\Items(type: 'string'), nullable: true),
                    new OA\Property(property: 'removed_images', type: 'array', items: new OA\Items(type: 'integer'), description: 'Ids of PropertyImage rows to delete.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Property updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'rejected_images', type: 'integer'),
                        new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function updateProperty(Request $request, $id)
    {
        $property = Property::where('owner_id', $request->user()->id)->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        if (is_string($request->amenities)) {
            $request->merge(['amenities' => json_decode($request->amenities, true) ?? []]);
        }
        if (is_string($request->images)) {
            $request->merge(['images' => json_decode($request->images, true) ?? []]);
        }
        if (is_string($request->removed_images)) {
            $request->merge(['removed_images' => json_decode($request->removed_images, true) ?? []]);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'location' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:apartment,house,villa,studio,office',
            'area' => 'sometimes|numeric|min:0',
            'price_per_month' => 'sometimes|numeric|min:0',
            'rooms' => 'sometimes|integer|min:0',
            'bathrooms' => 'sometimes|integer|min:0',
            'floor' => 'sometimes|integer|min:0',
            'capacity' => 'sometimes|integer|min:1',
            'amenities' => 'sometimes|array',
            'images' => 'sometimes|array|max:10',
            'images.*' => 'string',
            'removed_images' => 'sometimes|array',
            'removed_images.*' => 'exists:property_images,id',
        ]);

        DB::beginTransaction();

        try {
            $property->update(collect($validated)->only([
                'title', 'description', 'location', 'type', 'area',
                'price_per_month', 'rooms', 'bathrooms', 'floor', 'capacity', 'amenities',
            ])->toArray());

            if (!empty($validated['removed_images'])) {
                foreach ($validated['removed_images'] as $imageId) {
                    $image = PropertyImage::where('property_id', $property->id)->find($imageId);
                    if ($image) {
                        $path = public_path($image->image_url);
                        if (File::exists($path)) {
                            File::delete($path);
                        }
                        $image->delete();
                    }
                }
            }

            $rejected = 0;
            $writtenPaths = [];

            if (!empty($validated['images'])) {
                foreach ($validated['images'] as $base64) {
                    $imageUrl = $this->saveBase64Image($base64);
                    if (!$imageUrl) {
                        $rejected++;
                        continue;
                    }
                    $writtenPaths[] = $imageUrl;
                    PropertyImage::create([
                        'property_id' => $property->id,
                        'image_url' => $imageUrl,
                    ]);
                }
            }

            DB::commit();

            $message = 'Property updated successfully.';

            if ($rejected > 0) {
                $message .= ' ' . $this->rejectedImageMessage($rejected);
            }

            return response()->json([
                'message' => $message,
                'rejected_images' => $rejected,
                'property' => $property->load('images'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            // Remove the files this attempt wrote; the rollback only clears the
            // rows, so leaving them behind would orphan them on disk.
            foreach ($writtenPaths ?? [] as $path) {
                app(ImageUploadService::class)->deleteStored($path, 'uploads/properties');
            }
            return response()->json(['message' => 'Failed to update property.'], 500);
        }
    }

    #[OA\Delete(
        path: '/owner/properties/{id}',
        operationId: 'ownerDeleteProperty',
        tags: ['Owner'],
        summary: 'Delete a property',
        description: 'Deletes the property along with its stored images.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Property deleted.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function deleteProperty(Request $request, $id)
    {
        $property = Property::where('owner_id', $request->user()->id)
            ->with('images')
            ->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        foreach ($property->images as $image) {
            $path = public_path($image->image_url);
            if (File::exists($path)) {
                File::delete($path);
            }
            $image->delete();
        }

        $property->delete();

        return response()->json(['message' => 'Property deleted successfully.']);
    }

    #[OA\Get(
        path: '/owner/properties/{id}',
        operationId: 'ownerGetProperty',
        tags: ['Owner'],
        summary: 'Show one of the owner\'s properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The property.', content: new OA\JsonContent(ref: '#/components/schemas/Property')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function getProperty(Request $request, $id)
    {
        $property = Property::where('owner_id', $request->user()->id)
            ->with('images')
            ->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json($property);
    }

    #[OA\Get(
        path: '/owner/reservations',
        operationId: 'ownerReservations',
        tags: ['Owner'],
        summary: 'List reservations on the owner\'s properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated reservations.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reservation')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function reservations(Request $request)
    {
        $propertyIds = Property::where('owner_id', $request->user()->id)->pluck('id');

        $reservations = Reservation::whereIn('property_id', $propertyIds)
            ->with(['property', 'tenant', 'contract'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

    #[OA\Get(
        path: '/owner/reservations/{id}',
        operationId: 'ownerReservationDetails',
        tags: ['Owner'],
        summary: 'Show a reservation on the owner\'s properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The reservation.', content: new OA\JsonContent(ref: '#/components/schemas/Reservation')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function reservationDetails(Request $request, $id)
    {
        $propertyIds = Property::where('owner_id', $request->user()->id)->pluck('id');

        $reservation = Reservation::whereIn('property_id', $propertyIds)
            ->with(['property', 'tenant', 'contract'])
            ->find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        return response()->json($reservation);
    }

    #[OA\Put(
        path: '/owner/reservations/{id}/accept',
        operationId: 'ownerAcceptReservation',
        tags: ['Owner'],
        summary: 'Accept a pending reservation',
        description: 'Only pending reservations can be accepted, and the dates must not overlap an existing accepted booking.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reservation accepted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'reservation', ref: '#/components/schemas/Reservation'),
                        new OA\Property(property: 'total_amount', type: 'number', format: 'float'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, description: 'Not pending or the dates conflict.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function acceptReservation(Request $request, $id)
    {
        $ownerId = $request->user()->id;

        try {
            $reservation = DB::transaction(function () use ($ownerId, $id) {
                $reservation = $this->findOwnerReservation($ownerId, $id, true);

                if (!$reservation) {
                    return ['error' => 'Reservation not found or already processed.', 'code' => 404];
                }

                if ($reservation->status !== 'pending') {
                    return ['error' => 'Only pending reservations can be accepted.', 'code' => 422];
                }

                // Serialise competing acceptances for the same property so two
                // overlapping pending requests cannot both be accepted.
                Property::where('id', $reservation->property_id)->lockForUpdate()->first();

                // A previously accepted booking may already cover these dates.
                $conflict = Reservation::where('property_id', $reservation->property_id)
                    ->where('status', 'accepted')
                    ->overlapping($reservation->start_date, $reservation->end_date, $reservation->id)
                    ->exists();

                if ($conflict) {
                    return ['error' => 'These dates are already booked for this property.', 'code' => 422];
                }

                $reservation->update(['status' => 'accepted']);

                $reservation->loadMissing(['property', 'tenant']);

                $total = $reservation->totalAmount();

                Notification::create([
                    'user_id' => $reservation->tenant_id,
                    'title' => 'Reservation Accepted',
                    'message' => 'Your reservation for "' . $reservation->property->title . '" has been accepted.',
                    'type' => 'reservation',
                    'related_type' => Reservation::class,
                    'related_id' => $reservation->id,
                    'is_read' => false,
                ]);

                Notification::create([
                    'user_id' => $reservation->tenant_id,
                    'title' => 'Payment Required',
                    'message' => 'Your reservation for "' . $reservation->property->title . '" is accepted. '
                        . 'Please complete the payment of ' . number_format($total, 2) . ' MAD to generate your contract.',
                    'type' => 'payment',
                    'related_type' => Reservation::class,
                    'related_id' => $reservation->id,
                    'is_read' => false,
                ]);

                return ['reservation' => $reservation, 'total_amount' => $total];
            });
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Failed to accept the reservation.'], 500);
        }

        if (isset($reservation['error'])) {
            return response()->json(['message' => $reservation['error']], $reservation['code']);
        }

        return response()->json([
            'message' => 'Reservation accepted successfully.',
            'reservation' => $reservation['reservation']->load(['property', 'tenant']),
            'total_amount' => $reservation['total_amount'],
        ]);
    }

    #[OA\Put(
        path: '/owner/reservations/{id}/reject',
        operationId: 'ownerRejectReservation',
        tags: ['Owner'],
        summary: 'Reject a pending reservation',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reservation rejected.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'reservation', ref: '#/components/schemas/Reservation'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, description: 'The reservation is not pending.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function rejectReservation(Request $request, $id)
    {
        $ownerId = $request->user()->id;

        try {
            $reservation = DB::transaction(function () use ($ownerId, $id) {
                $reservation = $this->findOwnerReservation($ownerId, $id, true);

                if (!$reservation) {
                    return ['error' => 'Reservation not found or already processed.', 'code' => 404];
                }

                if ($reservation->status !== 'pending') {
                    return ['error' => 'Only pending reservations can be rejected.', 'code' => 422];
                }

                $reservation->update(['status' => 'rejected']);

                $reservation->loadMissing(['property', 'tenant']);

                Notification::create([
                    'user_id' => $reservation->tenant_id,
                    'title' => 'Reservation Rejected',
                    'message' => 'Your reservation for "' . $reservation->property->title . '" has been rejected.',
                    'type' => 'reservation',
                    'related_type' => Reservation::class,
                    'related_id' => $reservation->id,
                    'is_read' => false,
                ]);

                return ['reservation' => $reservation];
            });
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Failed to reject the reservation.'], 500);
        }

        if (isset($reservation['error'])) {
            return response()->json(['message' => $reservation['error']], $reservation['code']);
        }

        return response()->json([
            'message' => 'Reservation rejected successfully.',
            'reservation' => $reservation['reservation']->load(['property', 'tenant']),
        ]);
    }

    /**
     * Looks up a reservation that belongs to one of the owner's properties,
     * optionally locking the row for the duration of the transaction.
     */
    private function findOwnerReservation(int $ownerId, $id, bool $lock = false): ?Reservation
    {
        return Reservation::whereIn('property_id', Property::where('owner_id', $ownerId)->select('id'))
            ->with(['property', 'tenant'])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->find($id);
    }

    #[OA\Get(
        path: '/owner/contracts',
        operationId: 'ownerContracts',
        tags: ['Owner'],
        summary: 'List the owner\'s contracts',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated contracts.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Contract')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function contracts(Request $request)
    {
        $contracts = Contract::where('owner_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'tenant', 'latestPayment'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($contracts);
    }

    #[OA\Get(
        path: '/owner/contracts/{id}',
        operationId: 'ownerContractDetails',
        tags: ['Owner'],
        summary: 'Show one of the owner\'s contracts',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The contract.', content: new OA\JsonContent(ref: '#/components/schemas/Contract')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function contractDetails(Request $request, $id)
    {
        $contract = Contract::where('owner_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'tenant', 'latestPayment'])
            ->find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }

        return response()->json($contract);
    }

    #[OA\Get(
        path: '/owner/payments',
        operationId: 'ownerPayments',
        tags: ['Owner'],
        summary: 'List payments on the owner\'s properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated payments.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Payment')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function payments(Request $request)
    {
        $payments = Payment::whereHas('reservation.property', function ($q) use ($request) {
                $q->where('owner_id', $request->user()->id);
            })
            ->with(['reservation', 'property', 'tenant'])
            ->latest('payment_date')
            ->paginate($request->get('per_page', 15));

        return response()->json($payments);
    }

    /**
     * Property photos are sent as base64 data URIs, exactly like profile
     * avatars. The decoding and publishing lives in ImageUploadService so both
     * features share one implementation.
     */
    private function saveBase64Image(string $base64): ?string
    {
        return app(ImageUploadService::class)->storeDataUri(
            $base64,
            'uploads/properties',
            ImageUploadService::PROPERTY_MAX_BYTES
        );
    }
}
