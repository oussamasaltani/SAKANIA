<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ReservationController extends Controller
{
    #[OA\Post(
        path: '/reservations',
        operationId: 'reservationsStore',
        tags: ['Reservations'],
        summary: 'Request a reservation',
        description: 'Tenant only. The property must be approved, not owned by the caller, and free for the requested period. Rate limited to 10 requests per minute.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['property_id', 'start_date', 'duration_months'],
                properties: [
                    new OA\Property(property: 'property_id', type: 'integer', example: 3),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', description: 'Must not be in the past.', example: '2026-11-01'),
                    new OA\Property(property: 'duration_months', type: 'integer', minimum: 1, maximum: 36, example: 6),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Reservation created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Reservation created successfully.'),
                        new OA\Property(property: 'reservation', ref: '#/components/schemas/Reservation'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, description: 'The property is unavailable, the caller owns it or the dates overlap.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'start_date' => 'required|date|after_or_equal:today',
            'duration_months' => 'required|integer|min:1|max:36',
        ]);

        $property = Property::with('owner')->findOrFail($validated['property_id']);

        if ($property->status !== 'approved') {
            return response()->json(['message' => 'This property is not available for reservation.'], 422);
        }

        if ($property->owner_id === $request->user()->id) {
            return response()->json(['message' => 'You cannot reserve your own property.'], 422);
        }

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = $startDate->copy()->addMonths($validated['duration_months']);

        $duplicate = Reservation::where('tenant_id', $request->user()->id)
            ->where('property_id', $property->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'You already have a reservation request for this property and period.',
            ], 422);
        }

        try {
            $reservation = DB::transaction(function () use ($property, $startDate, $endDate, $request) {
                // Serialises concurrent requests so two tenants cannot book the
                // same property for overlapping dates.
                Property::whereKey($property->id)->lockForUpdate()->first();

                $hasConflict = Reservation::where('property_id', $property->id)
                    ->active()
                    ->overlapping($startDate, $endDate)
                    ->exists();

                if ($hasConflict) {
                    return null;
                }

                $reservation = Reservation::create([
                    'property_id' => $property->id,
                    'tenant_id' => $request->user()->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'pending',
                ]);

                // Only the owner of the reserved property is notified.
                Notification::create([
                    'user_id' => $property->owner_id,
                    'title' => 'New Reservation Request',
                    'message' => $request->user()->full_name . ' requested to rent "' . $property->title . '"'
                        . ' from ' . $startDate->format('d/m/Y') . ' to ' . $endDate->format('d/m/Y') . '.',
                    'type' => 'reservation',
                    'related_type' => Reservation::class,
                    'related_id' => $reservation->id,
                    'is_read' => false,
                ]);

                return $reservation;
            });
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Failed to create the reservation request.'], 500);
        }

        if (!$reservation) {
            return response()->json(['message' => 'The property is not available for the selected dates.'], 422);
        }

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => $reservation->load(['property', 'property.images']),
        ], 201);
    }

    #[OA\Put(
        path: '/reservations/{id}/cancel',
        operationId: 'reservationsCancel',
        tags: ['Reservations'],
        summary: 'Cancel a pending reservation',
        description: 'Tenant only. Only reservations that are still pending and belong to the caller can be cancelled.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reservation cancelled.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Reservation cancelled successfully.'),
                        new OA\Property(property: 'reservation', ref: '#/components/schemas/Reservation'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, description: 'The reservation is not pending.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
        ],
    )]
    public function cancel(Request $request, $id)
    {
        $reservation = Reservation::where('tenant_id', $request->user()->id)->find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        if ($reservation->status !== 'pending') {
            return response()->json(['message' => 'Only pending reservations can be cancelled.'], 422);
        }

        $reservation->update(['status' => 'cancelled']);

        // The owner keeps the calendar, so they have to know the dates are free again.
        $reservation->loadMissing('property');

        Notification::create([
            'user_id' => $reservation->property->owner_id,
            'title' => 'Reservation Cancelled',
            'message' => $request->user()->full_name . ' cancelled their reservation for "'
                . $reservation->property->title . '" from '
                . $reservation->start_date->format('d/m/Y') . ' to '
                . $reservation->end_date->format('d/m/Y') . '.',
            'type' => 'reservation',
            'related_type' => Reservation::class,
            'related_id' => $reservation->id,
            'is_read' => false,
        ]);

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'reservation' => $reservation->load(['property', 'property.images']),
        ]);
    }
}
