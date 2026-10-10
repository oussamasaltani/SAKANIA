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

class TenantController extends Controller
{
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

    public function myReservations(Request $request)
    {
        $reservations = Reservation::where('tenant_id', $request->user()->id)
            ->with(['property', 'property.images', 'contract'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

    public function myContracts(Request $request)
    {
        $contracts = Contract::where('tenant_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'owner', 'latestPayment'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($contracts);
    }

    public function contractDetails(Request $request, $id)
    {
        $contract = Contract::where('tenant_id', $request->user()->id)
            ->with(['reservation', 'reservation.property', 'reservation.property.images', 'owner', 'latestPayment'])
            ->findOrFail($id);

        return response()->json($contract);
    }

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
