<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;

class AdminController extends Controller
{
    private function clearAdminStatsCache(): void
    {
        Cache::forget('admin_dashboard_stats');
        Cache::forget('admin_recent_users');
        Cache::forget('admin_recent_properties');
        Cache::forget('admin_recent_reservations');
        Cache::forget('admin_dashboard_charts');
        Cache::forget('admin_users_stats');
        Cache::forget('admin_properties_stats');
        Cache::forget('admin_reservations_stats');
        Cache::forget('admin_contracts_stats');
        Cache::forget('admin_payments_stats');
    }

    #[OA\Get(
        path: '/admin/dashboard',
        operationId: 'adminDashboard',
        tags: ['Admin'],
        summary: 'Admin dashboard',
        description: 'Platform-wide counters, the most recent users, properties and reservations, and the yearly charts. Admin role only.',
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
                                new OA\Property(property: 'total_users', type: 'integer'),
                                new OA\Property(property: 'total_owners', type: 'integer'),
                                new OA\Property(property: 'total_tenants', type: 'integer'),
                                new OA\Property(property: 'total_properties', type: 'integer'),
                                new OA\Property(property: 'total_reservations', type: 'integer'),
                                new OA\Property(property: 'total_contracts', type: 'integer'),
                                new OA\Property(property: 'total_payments', type: 'integer'),
                                new OA\Property(property: 'total_revenue', type: 'number', format: 'float'),
                            ],
                        ),
                        new OA\Property(property: 'recent_users', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
                        new OA\Property(property: 'recent_properties', type: 'array', items: new OA\Items(ref: '#/components/schemas/Property')),
                        new OA\Property(property: 'recent_reservations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reservation')),
                        new OA\Property(property: 'charts', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function dashboard(Request $request)
    {
        $stats = Cache::remember('admin_dashboard_stats', 60, function () {
            $row = DB::selectOne('SELECT
                (SELECT COUNT(*) FROM users) as total_users,
                (SELECT COUNT(*) FROM users WHERE role_id = 2) as total_owners,
                (SELECT COUNT(*) FROM users WHERE role_id = 1) as total_tenants,
                (SELECT COUNT(*) FROM properties) as total_properties,
                (SELECT COUNT(*) FROM reservations) as total_reservations,
                (SELECT COUNT(*) FROM contracts) as total_contracts,
                (SELECT COUNT(*) FROM payments) as total_payments,
                (SELECT COALESCE(SUM(paid_amount), 0) FROM payments WHERE status = "paid") as total_revenue
            ');
            return [
                'total_users' => $row->total_users,
                'total_owners' => $row->total_owners,
                'total_tenants' => $row->total_tenants,
                'total_properties' => $row->total_properties,
                'total_reservations' => $row->total_reservations,
                'total_contracts' => $row->total_contracts,
                'total_payments' => $row->total_payments,
                'total_revenue' => $row->total_revenue,
            ];
        });

        $recentUsers = Cache::remember('admin_recent_users', 60, function () {
            return User::with('role')->latest()->take(5)->get();
        });
        $recentProperties = Cache::remember('admin_recent_properties', 60, function () {
            return Property::with('owner')->latest()->take(5)->get();
        });
        $recentReservations = Cache::remember('admin_recent_reservations', 60, function () {
            return Reservation::with(['property', 'tenant'])->latest()->take(5)->get();
        });

        $charts = Cache::remember('admin_dashboard_charts', 300, function () {
            $reservationsPerMonth = Reservation::select(
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as count')
            )
                ->whereYear('created_at', now()->year)
                ->groupBy(DB::raw('MONTH(created_at)'))
                ->pluck('count', 'month')
                ->toArray();

            $revenuePerMonth = Payment::where('status', 'paid')
                ->select(
                    DB::raw('MONTH(payment_date) as month'),
                    DB::raw('SUM(paid_amount) as total')
                )
                ->whereYear('payment_date', now()->year)
                ->groupBy(DB::raw('MONTH(payment_date)'))
                ->pluck('total', 'month')
                ->toArray();

            $usersPerMonth = User::select(
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as count')
            )
                ->whereYear('created_at', now()->year)
                ->groupBy(DB::raw('MONTH(created_at)'))
                ->pluck('count', 'month')
                ->toArray();

            return [
                'reservations_per_month' => $reservationsPerMonth,
                'revenue_per_month' => $revenuePerMonth,
                'users_per_month' => $usersPerMonth,
            ];
        });

        return response()->json([
            'stats' => $stats,
            'recent_users' => $recentUsers,
            'recent_properties' => $recentProperties,
            'recent_reservations' => $recentReservations,
            'charts' => $charts,
        ]);
    }

    #[OA\Get(
        path: '/admin/users',
        operationId: 'adminUsers',
        tags: ['Admin'],
        summary: 'List users',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches name, email or phone.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated users with global stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'users', type: 'object'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'owners', type: 'integer'),
                                new OA\Property(property: 'tenants', type: 'integer'),
                                new OA\Property(property: 'admins', type: 'integer'),
                                new OA\Property(property: 'active', type: 'integer'),
                                new OA\Property(property: 'inactive', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function users(Request $request)
    {
        $query = User::with('role');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->latest()->paginate($request->get('per_page', 15));

        $stats = Cache::remember('admin_users_stats', 120, function () {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                SUM(CASE WHEN role_id = 2 THEN 1 ELSE 0 END) as owners,
                SUM(CASE WHEN role_id = 1 THEN 1 ELSE 0 END) as tenants,
                SUM(CASE WHEN role_id = 3 THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
            FROM users');
            return [
                'total' => $row->total,
                'owners' => $row->owners,
                'tenants' => $row->tenants,
                'admins' => $row->admins,
                'active' => $row->active,
                'inactive' => $row->inactive,
            ];
        });

        return response()->json([
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    #[OA\Get(
        path: '/admin/users/{id}',
        operationId: 'adminViewUser',
        tags: ['Admin'],
        summary: 'Show a user',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The user.', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function viewUser(Request $request, $id)
    {
        $user = User::with('role')->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($user);
    }

    #[OA\Put(
        path: '/admin/users/{id}/activate',
        operationId: 'adminActivateUser',
        tags: ['Admin'],
        summary: 'Activate a user',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User activated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function activateUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['is_active' => true]);

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'User activated successfully.', 'user' => $user->load('role')]);
    }

    #[OA\Put(
        path: '/admin/users/{id}/deactivate',
        operationId: 'adminDeactivateUser',
        tags: ['Admin'],
        summary: 'Deactivate a user',
        description: 'An administrator cannot deactivate its own account nor the last active administrator.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User deactivated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function deactivateUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ($user->role_id === 3 && $user->is_active && $this->activeAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot deactivate the last active administrator.'], 422);
        }

        $user->update(['is_active' => false]);

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'User deactivated successfully.', 'user' => $user->load('role')]);
    }

    #[OA\Delete(
        path: '/admin/users/{id}',
        operationId: 'adminDeleteUser',
        tags: ['Admin'],
        summary: 'Delete a user',
        description: 'An administrator cannot delete its own account nor the last active administrator.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User deleted.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function deleteUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->role_id === 3 && $user->is_active && $this->activeAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot delete the last active administrator.'], 422);
        }

        $user->delete();

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    private function activeAdminCount(): int
    {
        return User::where('role_id', 3)->where('is_active', true)->count();
    }

    #[OA\Get(
        path: '/admin/properties',
        operationId: 'adminProperties',
        tags: ['Admin'],
        summary: 'List properties',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches title or location.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated properties with global stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'properties', type: 'object'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'approved', type: 'integer'),
                                new OA\Property(property: 'pending', type: 'integer'),
                                new OA\Property(property: 'rejected', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function properties(Request $request)
    {
        $query = Property::with(['owner', 'images']);

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

        $properties = $query->latest()->paginate($request->get('per_page', 15));

        $stats = Cache::remember('admin_properties_stats', 120, function () {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected
            FROM properties');
            return [
                'total' => $row->total,
                'approved' => $row->approved,
                'pending' => $row->pending,
                'rejected' => $row->rejected,
            ];
        });

        return response()->json([
            'properties' => $properties,
            'stats' => $stats,
        ]);
    }

    #[OA\Get(
        path: '/admin/properties/{id}',
        operationId: 'adminViewProperty',
        tags: ['Admin'],
        summary: 'Show a property',
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
    public function viewProperty(Request $request, $id)
    {
        $property = Property::with(['owner', 'images'])->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json($property);
    }

    #[OA\Put(
        path: '/admin/properties/{id}/approve',
        operationId: 'adminApproveProperty',
        tags: ['Admin'],
        summary: 'Approve a property',
        description: 'Sets the property to `approved` and notifies its owner.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Property approved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function approveProperty(Request $request, $id)
    {
        $property = Property::find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $property->update(['status' => 'approved']);

        Notification::create([
            'user_id' => $property->owner_id,
            'title' => 'Property Approved',
            'message' => 'Your property "' . $property->title . '" has been approved.',
            'type' => 'property',
            'related_type' => Property::class,
            'related_id' => $property->id,
            'is_read' => false,
        ]);

        $this->clearAdminStatsCache();

        return response()->json([
            'message' => 'Property approved successfully.',
            'property' => $property->load(['owner', 'images']),
        ]);
    }

    #[OA\Put(
        path: '/admin/properties/{id}/reject',
        operationId: 'adminRejectProperty',
        tags: ['Admin'],
        summary: 'Reject a property',
        description: 'Sets the property to `rejected` and notifies its owner.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Property rejected.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'property', ref: '#/components/schemas/Property'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function rejectProperty(Request $request, $id)
    {
        $property = Property::find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $property->update(['status' => 'rejected']);

        Notification::create([
            'user_id' => $property->owner_id,
            'title' => 'Property Rejected',
            'message' => 'Your property "' . $property->title . '" has been rejected.',
            'type' => 'property',
            'related_type' => Property::class,
            'related_id' => $property->id,
            'is_read' => false,
        ]);

        $this->clearAdminStatsCache();

        return response()->json([
            'message' => 'Property rejected successfully.',
            'property' => $property->load(['owner', 'images']),
        ]);
    }

    #[OA\Delete(
        path: '/admin/properties/{id}',
        operationId: 'adminDeleteProperty',
        tags: ['Admin'],
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
        $property = Property::with('images')->find($id);

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

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'Property deleted successfully.']);
    }

    #[OA\Get(
        path: '/admin/reservations',
        operationId: 'adminReservations',
        tags: ['Admin'],
        summary: 'List reservations',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches property title/location or tenant name/email.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'accepted', 'rejected', 'cancelled'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated reservations with global stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'reservations', type: 'object'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'pending', type: 'integer'),
                                new OA\Property(property: 'accepted', type: 'integer'),
                                new OA\Property(property: 'rejected', type: 'integer'),
                                new OA\Property(property: 'cancelled', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function reservations(Request $request)
    {
        $query = Reservation::with(['property', 'tenant', 'contract']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('property', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            })->orWhereHas('tenant', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $reservations = $query->latest()->paginate($request->get('per_page', 15));

        $stats = Cache::remember('admin_reservations_stats', 120, function () {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = "accepted" THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled
            FROM reservations');
            return [
                'total' => $row->total,
                'pending' => $row->pending,
                'accepted' => $row->accepted,
                'rejected' => $row->rejected,
                'cancelled' => $row->cancelled,
            ];
        });

        return response()->json([
            'reservations' => $reservations,
            'stats' => $stats,
        ]);
    }

    #[OA\Get(
        path: '/admin/reservations/{id}',
        operationId: 'adminViewReservation',
        tags: ['Admin'],
        summary: 'Show a reservation',
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
    public function viewReservation(Request $request, $id)
    {
        $reservation = Reservation::with(['property', 'tenant'])->find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        return response()->json($reservation);
    }

    #[OA\Delete(
        path: '/admin/reservations/{id}',
        operationId: 'adminDeleteReservation',
        tags: ['Admin'],
        summary: 'Delete a reservation',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reservation deleted.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function deleteReservation(Request $request, $id)
    {
        $reservation = Reservation::find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $reservation->delete();

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'Reservation deleted successfully.']);
    }

    #[OA\Get(
        path: '/admin/contracts',
        operationId: 'adminContracts',
        tags: ['Admin'],
        summary: 'List contracts',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches property title, tenant or owner name.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'ended', 'completed'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated contracts with global stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'contracts', type: 'object'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'draft', type: 'integer'),
                                new OA\Property(property: 'active', type: 'integer'),
                                new OA\Property(property: 'ended', type: 'integer'),
                                new OA\Property(property: 'completed', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function contracts(Request $request)
    {
        $query = Contract::with(['reservation', 'reservation.property', 'owner', 'tenant', 'latestPayment']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('reservation.property', function ($q2) use ($search) {
                    $q2->where('title', 'like', "%{$search}%");
                })->orWhereHas('tenant', function ($q2) use ($search) {
                    $q2->where('full_name', 'like', "%{$search}%");
                })->orWhereHas('owner', function ($q2) use ($search) {
                    $q2->where('full_name', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $contracts = $query->latest()->paginate($request->get('per_page', 15));

        $stats = Cache::remember('admin_contracts_stats', 120, function () {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "ended" THEN 1 ELSE 0 END) as ended,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed
            FROM contracts');
            return [
                'total' => $row->total,
                'draft' => $row->draft,
                'active' => $row->active,
                'ended' => $row->ended,
                'completed' => $row->completed,
            ];
        });

        return response()->json([
            'contracts' => $contracts,
            'stats' => $stats,
        ]);
    }

    #[OA\Get(
        path: '/admin/contracts/{id}',
        operationId: 'adminViewContract',
        tags: ['Admin'],
        summary: 'Show a contract',
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
    public function viewContract(Request $request, $id)
    {
        $contract = Contract::with(['reservation', 'reservation.property', 'owner', 'tenant', 'latestPayment'])->find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }

        return response()->json($contract);
    }

    #[OA\Put(
        path: '/admin/contracts/{id}/status',
        operationId: 'adminUpdateContractStatus',
        tags: ['Admin'],
        summary: 'Update a contract status',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'ended', 'completed'], example: 'active'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contract status updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'contract', ref: '#/components/schemas/Contract'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function updateContractStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:draft,active,ended,completed',
        ]);

        $contract = Contract::find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }

        $contract->update(['status' => $validated['status']]);

        $this->clearAdminStatsCache();

        return response()->json([
            'message' => 'Contract status updated successfully.',
            'contract' => $contract->load(['reservation', 'reservation.property', 'owner', 'tenant', 'latestPayment']),
        ]);
    }

    #[OA\Delete(
        path: '/admin/contracts/{id}',
        operationId: 'adminDeleteContract',
        tags: ['Admin'],
        summary: 'Delete a contract',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contract deleted.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function deleteContract(Request $request, $id)
    {
        $contract = Contract::find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }

        $contract->delete();

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'Contract deleted successfully.']);
    }

    #[OA\Get(
        path: '/admin/payments',
        operationId: 'adminPayments',
        tags: ['Admin'],
        summary: 'List payments',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Matches tenant name/email or transaction reference.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'failed', 'refunded'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated payments with global stats.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'payments', type: 'object'),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'total_revenue', type: 'number', format: 'float'),
                                new OA\Property(property: 'paid', type: 'integer'),
                                new OA\Property(property: 'pending', type: 'integer'),
                                new OA\Property(property: 'failed', type: 'integer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
    public function payments(Request $request)
    {
        $query = Payment::with(['reservation', 'reservation.property', 'tenant']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('tenant', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('transaction_ref', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest('payment_date')->paginate($request->get('per_page', 15));

        $stats = Cache::remember('admin_payments_stats', 120, function () {
            $row = DB::selectOne('SELECT
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = "paid" THEN paid_amount ELSE 0 END), 0) as total_revenue,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            FROM payments');
            return [
                'total' => $row->total,
                'total_revenue' => $row->total_revenue,
                'paid' => $row->paid,
                'pending' => $row->pending,
                'failed' => $row->failed,
            ];
        });

        return response()->json([
            'payments' => $payments,
            'stats' => $stats,
        ]);
    }

    #[OA\Get(
        path: '/admin/payments/{id}',
        operationId: 'adminViewPayment',
        tags: ['Admin'],
        summary: 'Show a payment',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The payment.', content: new OA\JsonContent(ref: '#/components/schemas/Payment')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function viewPayment(Request $request, $id)
    {
        $payment = Payment::with(['reservation', 'reservation.property', 'tenant'])->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json($payment);
    }

    #[OA\Put(
        path: '/admin/payments/{id}/status',
        operationId: 'adminUpdatePaymentStatus',
        tags: ['Admin'],
        summary: 'Update a payment status',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['paid', 'pending', 'failed', 'refunded'], example: 'paid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Payment status updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'payment', ref: '#/components/schemas/Payment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function updatePaymentStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:paid,pending,failed,refunded',
        ]);

        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $payment->update(['status' => $validated['status']]);

        $this->clearAdminStatsCache();

        return response()->json([
            'message' => 'Payment status updated successfully.',
            'payment' => $payment->load(['reservation', 'tenant']),
        ]);
    }

    #[OA\Delete(
        path: '/admin/payments/{id}',
        operationId: 'adminDeletePayment',
        tags: ['Admin'],
        summary: 'Delete a payment',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment deleted.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function deletePayment(Request $request, $id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $payment->delete();

        $this->clearAdminStatsCache();

        return response()->json(['message' => 'Payment deleted successfully.']);
    }
}
