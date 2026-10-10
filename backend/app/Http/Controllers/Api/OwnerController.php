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

class OwnerController extends Controller
{
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

    public function reservations(Request $request)
    {
        $propertyIds = Property::where('owner_id', $request->user()->id)->pluck('id');

        $reservations = Reservation::whereIn('property_id', $propertyIds)
            ->with(['property', 'tenant', 'contract'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

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

    public function contracts(Request $request)
    {
        $contracts = Contract::where('owner_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'tenant', 'latestPayment'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($contracts);
    }

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
