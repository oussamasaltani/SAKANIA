<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class TenantController extends Controller
{
    #[OA\Get(
        path: '/tenant/dashboard',
        operationId: 'tenantDashboard',
        tags: ['Tenant'],
        summary: 'Tenant dashboard',
        description: 'Aggregated counters, the five most recent reservations and a set of featured properties. Tenant role only.',
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
                                new OA\Property(property: 'total_reservations', type: 'integer'),
                                new OA\Property(property: 'active_contracts', type: 'integer'),
                                new OA\Property(property: 'total_payments', type: 'number', format: 'float'),
                                new OA\Property(property: 'pending_reservations', type: 'integer'),
                            ],
                        ),
                        new OA\Property(property: 'recent_reservations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reservation')),
                        new OA\Property(property: 'featured_properties', type: 'array', items: new OA\Items(ref: '#/components/schemas/Property')),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function dashboard(Request $request)
    {
        $tenant = $request->user();
        $tenantId = $tenant->id;

        $stats = Cache::remember("tenant_dashboard_{$tenantId}", 60, function () use ($tenantId) {
            $row = DB::selectOne('SELECT
                (SELECT COUNT(*) FROM reservations WHERE tenant_id = ?) as total_reservations,
                (SELECT COUNT(*) FROM contracts WHERE tenant_id = ? AND status = "active") as active_contracts,
                (SELECT COALESCE(SUM(paid_amount), 0) FROM payments WHERE tenant_id = ?) as total_payments,
                (SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND status = "pending") as pending_reservations
            ', [$tenantId, $tenantId, $tenantId, $tenantId]);

            return [
                'total_reservations' => $row->total_reservations,
                'active_contracts' => $row->active_contracts,
                'total_payments' => $row->total_payments,
                'pending_reservations' => $row->pending_reservations,
            ];
        });

        $recentReservations = Reservation::where('tenant_id', $tenantId)
            ->with(['property', 'property.images', 'contract'])
            ->latest()
            ->take(5)
            ->get();

        $featuredProperties = Property::with(['owner', 'images'])
            ->where('status', 'approved')
            ->latest()
            ->take(6)
            ->get();

        return response()->json([
            'stats' => $stats,
            'recent_reservations' => $recentReservations,
            'featured_properties' => $featuredProperties,
        ]);
    }

    #[OA\Get(
        path: '/tenant/reservations',
        operationId: 'tenantReservations',
        tags: ['Tenant'],
        summary: 'List the tenant\'s reservations',
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
    public function myReservations(Request $request)
    {
        $reservations = Reservation::where('tenant_id', $request->user()->id)
            ->with(['property', 'property.images', 'contract'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

    #[OA\Get(
        path: '/tenant/contracts',
        operationId: 'tenantContracts',
        tags: ['Tenant'],
        summary: 'List the tenant\'s contracts',
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
    public function myContracts(Request $request)
    {
        $contracts = Contract::where('tenant_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'owner', 'latestPayment'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($contracts);
    }

    #[OA\Get(
        path: '/tenant/contracts/{id}',
        operationId: 'tenantContractDetails',
        tags: ['Tenant'],
        summary: 'Show one of the tenant\'s contracts',
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
        $contract = Contract::where('tenant_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'reservation.property.images', 'owner', 'latestPayment'])
            ->findOrFail($id);

        return response()->json($contract);
    }

    #[OA\Get(
        path: '/tenant/payments',
        operationId: 'tenantPayments',
        tags: ['Tenant'],
        summary: 'List the tenant\'s payments',
        description: 'Paginated payments with the cumulative totals paid and remaining.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Payments and totals.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'payments', ref: '#/components/schemas/Payment'),
                        new OA\Property(property: 'total_paid', type: 'number', format: 'float'),
                        new OA\Property(property: 'total_remaining', type: 'number', format: 'float'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function myPayments(Request $request)
    {
        $query = Payment::where('tenant_id', $request->user()->id)
            ->with(['reservation', 'reservation.property'])
            ->latest('payment_date');

        $payments = $query->paginate($request->get('per_page', 15));

        $totals = \Illuminate\Support\Facades\DB::selectOne('SELECT
            COALESCE(SUM(paid_amount), 0) as total_paid,
            COALESCE(SUM(remaining_balance), 0) as total_remaining
        FROM payments WHERE tenant_id = ?', [$request->user()->id]);

        return response()->json([
            'payments' => $payments,
            'total_paid' => $totals->total_paid,
            'total_remaining' => $totals->total_remaining,
        ]);
    }
}
