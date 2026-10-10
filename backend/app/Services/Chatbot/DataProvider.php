<?php

namespace App\Services\Chatbot;

use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The only place the chatbot is allowed to read the database.
 *
 * Every method here takes the authenticated user and scopes its query to what
 * that user is entitled to see. A reservation is only ever loaded through the
 * tenant who owns it or the owner of the property it is on; a payment only
 * through its tenant or the owner of the reserved property; a message only
 * through a conversation the user is a party of.
 *
 * An id typed by the user is a hint, never a grant: it is applied to a query that
 * is already scoped, so pointing at somebody else's reservation returns the same
 * "not found" as pointing at nothing.
 *
 * The returned arrays are the verified facts handed to the model. Nothing outside
 * them may be stated as fact.
 */
class DataProvider
{
    private const SEARCH_LIMIT = 5;

    /**
     * Criteria that may be dropped, in order, when nothing matches. The label is
     * what gets reported to the user as "this criterion was relaxed".
     */
    private const RELAXABLE = [
        ['budget', ['min_price', 'max_price']],
        ['rooms', ['rooms']],
    ];

    /**
     * Property search over approved properties, with the criteria actually
     * applied reported back so a near match can be explained honestly.
     *
     * @param  array<string, mixed>  $slots
     */
    public function searchProperties(array $slots, ?User $user): array
    {
        $applied = $this->appliedCriteria($slots);

        $exact = $this->runSearch($slots, $user);

        if ($exact->isNotEmpty()) {
            return [
                'match_type' => 'exact',
                'criteria_applied' => $applied,
                'count' => $exact->count(),
                'properties' => $exact->map(fn (Property $p) => $this->presentProperty($p))->all(),
            ];
        }

        // Nothing matched every criterion. Relax one criterion at a time, and
        // report which one had to be dropped so the answer can say so.
        foreach (self::RELAXABLE as [$label, $keys]) {
            $matches = $this->runSearch(array_diff_key($slots, array_flip($keys)), $user);

            if ($matches->isNotEmpty()) {
                return [
                    'match_type' => 'relaxed',
                    'criteria_applied' => $applied,
                    'relaxed_criteria' => [$label],
                    'kept_criteria' => array_diff_key($applied, array_flip($keys)),
                    'count' => $matches->count(),
                    'properties' => $matches->map(fn (Property $p) => $this->presentProperty($p))->all(),
                ];
            }
        }

        return [
            'match_type' => 'none',
            'criteria_applied' => $applied,
            'relaxed_criteria' => array_column(self::RELAXABLE, 0),
            'count' => 0,
            'properties' => [],
        ];
    }

    /**
     * Which criteria are present in the slots, in the wording shown to the user.
     *
     * @return array<string, mixed>
     */
    private function appliedCriteria(array $slots): array
    {
        $labels = [
            'location' => 'location',
            'property_type' => 'type',
            'min_price' => 'minimum price',
            'max_price' => 'maximum price',
            'rooms' => 'rooms',
            'bathrooms' => 'bathrooms',
            'min_area' => 'area',
        ];

        $applied = [];

        foreach ($labels as $key => $label) {
            if (isset($slots[$key]) && $slots[$key] !== '') {
                $applied[$label] = $slots[$key];
            }
        }

        $window = $this->availabilityWindow($slots);

        if ($window) {
            $applied['available from'] = $window['start']->toDateString();
            $applied['duration months'] = $window['months'];
        }

        return $applied;
    }

    /**
     * The query itself, applied to approved properties only.
     *
     * @return Collection<int, Property>
     */
    private function runSearch(array $slots, ?User $user)
    {
        $query = Property::query()
            ->where('status', 'approved')
            ->with('images');

        if (! empty($slots['location'])) {
            $query->where('location', 'like', '%'.$slots['location'].'%');
        }

        if (! empty($slots['property_type'])) {
            $query->where('type', $slots['property_type']);
        }

        if (isset($slots['min_price'])) {
            $query->where('price_per_month', '>=', (float) $slots['min_price']);
        }

        if (isset($slots['max_price'])) {
            $query->where('price_per_month', '<=', (float) $slots['max_price']);
        }

        if (isset($slots['rooms'])) {
            $query->where('rooms', '>=', (int) $slots['rooms']);
        }

        if (isset($slots['bathrooms'])) {
            $query->where('bathrooms', '>=', (int) $slots['bathrooms']);
        }

        if (isset($slots['min_area'])) {
            $query->where('area', '>=', (float) $slots['min_area']);
        }

        // Availability: a period the user gave removes properties already held by
        // an active reservation on overlapping dates.
        $window = $this->availabilityWindow($slots);

        if ($window) {
            $this->excludeReserved($query, $window['start'], $window['end']);
        }

        // Own properties are excluded: a user cannot reserve their own.
        if ($user && in_array($user->role_id, [1, 2], true)) {
            $query->where('owner_id', '!=', $user->id);
        }

        return $query->orderBy('price_per_month')->limit(self::SEARCH_LIMIT)->get();
    }

    /**
     * @param  Builder<Property>  $query
     */
    private function excludeReserved(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->whereNotIn('id', function ($sub) use ($start, $end) {
            $sub->select('property_id')
                ->from('reservations')
                ->whereIn('status', ['pending', 'accepted'])
                ->whereDate('start_date', '<', $end->toDateString())
                ->whereDate('end_date', '>', $start->toDateString());
        });
    }

    /**
     * @return array{start: Carbon, end: Carbon, months: int}|null
     */
    private function availabilityWindow(array $slots): ?array
    {
        $months = (int) ($slots['duration_months'] ?? 0);

        if ($months < 1) {
            return null;
        }

        $start = isset($slots['start_date'])
            ? Carbon::parse($slots['start_date'])->startOfDay()
            : Carbon::today();

        if ($start->isPast()) {
            $start = Carbon::today();
        }

        return [
            'start' => $start,
            'end' => $start->copy()->addMonths($months),
            'months' => $months,
        ];
    }

    private function presentProperty(Property $property): array
    {
        return [
            'id' => $property->id,
            'title' => $property->title,
            'location' => $property->location,
            'type' => $property->type,
            'price_per_month_mad' => (float) $property->price_per_month,
            'area_m2' => $property->area !== null ? (float) $property->area : null,
            'rooms' => (int) $property->rooms,
            'bathrooms' => (int) $property->bathrooms,
            'capacity' => (int) $property->capacity,
            'amenities' => $property->amenities,
            'has_photos' => $property->relationLoaded('images') ? $property->images->isNotEmpty() : null,
            'page' => '/properties/'.$property->id,
        ];
    }

    /**
     * A property the user is entitled to look at: any approved one, plus their
     * own whatever its moderation status.
     *
     * @param  array<string, mixed>  $slots
     */
    public function propertyDetails(array $slots, ?User $user): array
    {
        $propertyId = $slots['property_id'] ?? null;

        if (! $propertyId) {
            return ['found' => false, 'reason' => 'no_property_identified'];
        }

        $property = Property::query()
            ->with('images')
            ->when(
                $user?->role_id === 2,
                fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->where('status', 'approved')->orWhere('owner_id', $user->id)),
                fn (Builder $q) => $q->where('status', 'approved')
            )
            ->find((int) $propertyId);

        if (! $property) {
            return ['found' => false, 'reason' => 'not_found_or_not_accessible'];
        }

        $reservations = Reservation::where('property_id', $property->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->get(['start_date', 'end_date', 'status']);

        return [
            'found' => true,
            'property' => $this->presentProperty($property),
            'approval_status' => $property->status,
            'booked_periods' => $reservations->map(fn (Reservation $r) => [
                'start' => $r->start_date?->toDateString(),
                'end' => $r->end_date?->toDateString(),
                'status' => $r->status,
            ])->all(),
        ];
    }

    /**
     * The reservations the user is party to, newest first.
     *
     * @return array{reservations: array<int, array<string, mixed>>, total: int}
     */
    public function reservationsFor(User $user, ?string $reference = null): array
    {
        $query = Reservation::query()
            ->with(['property', 'contract'])
            ->where(function (Builder $q) use ($user) {
                if ($user->role_id === 2) {
                    $q->whereIn('property_id', Property::where('owner_id', $user->id)->select('id'));
                } else {
                    $q->where('tenant_id', $user->id);
                }
            });

        if ($reference) {
            $id = $this->referenceToId($reference);
            $query->whereKey($id);
        }

        $reservations = $query->orderByDesc('created_at')->limit(10)->get();

        return [
            'total' => $reservations->count(),
            'reservations' => $reservations->map(fn (Reservation $r) => $this->presentReservation($r, $user))->all(),
        ];
    }

    /**
     * One reservation, scoped. Returns null when it does not exist or the user
     * is not a party to it; the caller cannot tell the two apart.
     */
    public function findReservation(int $id, User $user): ?Reservation
    {
        return Reservation::query()
            ->with(['property', 'property.owner', 'tenant', 'payments', 'contract'])
            ->where(function (Builder $q) use ($user) {
                if ($user->role_id === 2) {
                    $q->whereIn('property_id', Property::where('owner_id', $user->id)->select('id'));
                } else {
                    $q->where('tenant_id', $user->id);
                }
            })
            ->whereKey($id)
            ->first();
    }

    /**
     * Resolves the reservation the user means: the one they named, otherwise the
     * most recent one they are party to.
     */
    public function resolveTargetReservation(array $slots, User $user): ?Reservation
    {
        if (! empty($slots['reservation_id'])) {
            return $this->findReservation((int) $slots['reservation_id'], $user);
        }

        $query = Reservation::query()->where(function (Builder $q) use ($user) {
            if ($user->role_id === 2) {
                $q->whereIn('property_id', Property::where('owner_id', $user->id)->select('id'));
            } else {
                $q->where('tenant_id', $user->id);
            }
        });

        if (! empty($slots['property_id'])) {
            $query->where('property_id', (int) $slots['property_id']);
        }

        return $query->orderByDesc('created_at')->first();
    }

    private function presentReservation(Reservation $reservation, User $user): array
    {
        $payment = $reservation->relationLoaded('payments')
            ? $reservation->payments->sortByDesc('payment_date')->first()
            : null;

        if ($payment === null && ! $reservation->relationLoaded('payments')) {
            $payment = $reservation->payments()->orderByDesc('payment_date')->first();
        }

        return [
            'reference' => $reservation->reference,
            'status' => $reservation->status,
            'property' => $reservation->property ? [
                'id' => $reservation->property->id,
                'title' => $reservation->property->title,
                'location' => $reservation->property->location,
                'monthly_price_mad' => (float) $reservation->property->price_per_month,
            ] : null,
            'start_date' => $reservation->start_date?->toDateString(),
            'end_date' => $reservation->end_date?->toDateString(),
            'duration_months' => $reservation->durationInMonths(),
            'total_amount_mad' => $reservation->totalAmount(),
            'paid_amount_mad' => $payment ? (float) $payment->paid_amount : 0.0,
            'remaining_balance_mad' => $payment ? (float) $payment->remaining_balance : $reservation->totalAmount(),
            'payment_status' => $payment?->status,
            'has_payment' => $payment !== null,
            'has_contract' => $reservation->relationLoaded('contract') && $reservation->contract !== null,
            'viewed_by' => $reservation->tenant_id === $user->id ? 'tenant' : 'owner',
            'page' => $user->role_id === 2 ? '/owner/reservations/'.$reservation->id : '/tenant/reservations',
        ];
    }

    /**
     * Payment state for a reservation, scoped like the reservation itself.
     */
    public function paymentFor(Reservation $reservation, User $user): array
    {
        $payment = $reservation->payments()->orderByDesc('payment_date')->first();

        $data = [
            'reservation_reference' => $reservation->reference,
            'reservation_status' => $reservation->status,
            'total_amount_mad' => $reservation->totalAmount(),
            'duration_months' => $reservation->durationInMonths(),
            'has_payment_record' => $payment !== null,
        ];

        if ($payment) {
            $data += [
                'payment_status' => $payment->status,
                'payment_method' => $payment->method,
                'transaction_reference' => $payment->transaction_ref,
                'last_payment_date' => $payment->payment_date?->toDateString(),
                'last_installment_mad' => (float) $payment->amount,
                'paid_amount_mad' => (float) $payment->paid_amount,
                'remaining_balance_mad' => (float) $payment->remaining_balance,
                'receipt_page' => '/api/payments/'.$payment->id.'/receipt',
            ];
        }

        if ($user->role_id === 2) {
            $data['tenant_name'] = $reservation->tenant->full_name ?? null;
        }

        return $data;
    }

    /**
     * Contract state for a reservation, plus whether the PDF is really on disk.
     */
    public function contractFor(Reservation $reservation): array
    {
        $contract = $reservation->contract()->with('owner')->first();

        if (! $contract) {
            return [
                'exists' => false,
                'reservation_reference' => $reservation->reference,
                'reservation_status' => $reservation->status,
                'payment_status' => $reservation->payments()->orderByDesc('payment_date')->value('status'),
                'contract_generated' => false,
            ];
        }

        return [
            'exists' => true,
            'contract_generated' => true,
            'reference' => $contract->reference,
            'status' => $contract->status,
            'created_at' => $contract->created_at?->toDateString(),
            'file_downloadable' => $contract->isDownloadable(),
            'download_endpoint' => '/api/contracts/'.$contract->id.'/download',
        ];
    }

    /**
     * The user's contracts, scoped to the contracts they are a party of.
     */
    public function contractsFor(User $user): array
    {
        $query = Contract::query()
            ->with(['reservation.property', 'latestPayment'])
            ->where(function (Builder $q) use ($user) {
                $q->where('tenant_id', $user->id)->orWhere('owner_id', $user->id);
            });

        return [
            'total' => $query->count(),
            'contracts' => $query->orderByDesc('created_at')->limit(10)->get()->map(fn (Contract $c) => [
                'reference' => $c->reference,
                'status' => $c->status,
                'created_at' => $c->created_at?->toDateString(),
                'property_title' => $c->reservation?->property?->title,
                'total_amount_mad' => $c->total_amount !== null ? (float) $c->total_amount : null,
                'paid_amount_mad' => $c->paid_amount !== null ? (float) $c->paid_amount : null,
                'remaining_balance_mad' => $c->remaining_balance !== null ? (float) $c->remaining_balance : null,
                'file_downloadable' => $c->isDownloadable(),
            ])->all(),
        ];
    }

    /**
     * Notifications of the user, newest first. Notifications are only ever read
     * for their own user id.
     */
    public function notificationsFor(User $user, int $limit = 8): array
    {
        $notifications = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'unread_count' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
            'total' => Notification::where('user_id', $user->id)->count(),
            'latest' => $notifications->map(fn (Notification $n) => [
                'title' => $n->title,
                'message' => $n->message,
                'type' => $n->type,
                'is_read' => (bool) $n->is_read,
                'created_at' => $n->created_at?->toDateString(),
            ])->all(),
        ];
    }

    /**
     * Conversations of the user, plus the unread message counters. Messages and
     * notifications stay separate: this reads messages, never notifications.
     */
    public function messagesFor(User $user): array
    {
        $conversations = Conversation::forUser($user)
            ->with(['property', 'tenant', 'owner'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return [
            'unread_messages' => Message::where('receiver_id', $user->id)->where('is_read', false)->count(),
            'conversations' => $conversations->map(function (Conversation $c) use ($user) {
                $counterpart = $c->counterpartFor($user);
                $last = $c->messages()->orderByDesc('sent_at')->first();

                return [
                    'property_title' => $c->property?->title,
                    'property_location' => $c->property?->location,
                    'with' => $counterpart?->full_name,
                    'with_role' => $counterpart?->role_id === 2 ? 'owner' : 'tenant',
                    'reservation_reference' => $c->reservation?->reference,
                    'unread_count' => $c->unreadCountFor($user),
                    'last_message_at' => $last?->sent_at?->toDateString(),
                    'page' => ($user->role_id === 2 ? '/owner/messages' : '/tenant/messages'),
                ];
            })->all(),
        ];
    }

    /**
     * The owner's own view of their properties and pending requests.
     */
    public function ownerOverview(User $user): array
    {
        $propertyIds = Property::where('owner_id', $user->id)->pluck('id');

        $byStatus = Property::where('owner_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'properties' => [
                'total' => (int) $byStatus->sum(),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'pending_approval' => (int) ($byStatus['pending'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'listing' => Property::where('owner_id', $user->id)->orderByDesc('created_at')->limit(5)->get()
                    ->map(fn (Property $p) => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'status' => $p->status,
                        'price_per_month_mad' => (float) $p->price_per_month,
                    ])->all(),
            ],
            'reservations' => [
                'pending_needs_action' => Reservation::whereIn('property_id', $propertyIds)->where('status', 'pending')->count(),
                'accepted' => Reservation::whereIn('property_id', $propertyIds)->where('status', 'accepted')->count(),
                'rejected' => Reservation::whereIn('property_id', $propertyIds)->where('status', 'rejected')->count(),
            ],
            'revenue_paid_mad' => (float) Payment::whereIn('reservation_id', Reservation::whereIn('property_id', $propertyIds)->select('id'))
                ->where('status', 'paid')
                ->sum('paid_amount'),
            'contracts_active' => Contract::where('owner_id', $user->id)->where('status', 'active')->count(),
            'unread_messages' => Message::where('receiver_id', $user->id)->where('is_read', false)->count(),
            'unread_notifications' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
        ];
    }

    /**
     * The tenant's own counters.
     */
    public function tenantOverview(User $user): array
    {
        return [
            'reservations' => [
                'total' => Reservation::where('tenant_id', $user->id)->count(),
                'pending' => Reservation::where('tenant_id', $user->id)->where('status', 'pending')->count(),
                'accepted' => Reservation::where('tenant_id', $user->id)->where('status', 'accepted')->count(),
                'rejected' => Reservation::where('tenant_id', $user->id)->where('status', 'rejected')->count(),
                'cancelled' => Reservation::where('tenant_id', $user->id)->where('status', 'cancelled')->count(),
            ],
            'payments' => [
                'total_paid_mad' => (float) Payment::where('tenant_id', $user->id)->sum('paid_amount'),
                'total_remaining_mad' => (float) Payment::where('tenant_id', $user->id)->sum('remaining_balance'),
                'records' => Payment::where('tenant_id', $user->id)->count(),
            ],
            'contracts_active' => Contract::where('tenant_id', $user->id)->where('status', 'active')->count(),
            'unread_messages' => Message::where('receiver_id', $user->id)->where('is_read', false)->count(),
            'unread_notifications' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
        ];
    }

    /**
     * The user's own account fields. Nothing sensitive is selected.
     */
    public function profileFor(User $user): array
    {
        $user->loadMissing('role');

        return [
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_name' => $user->company_name,
            'role' => $user->role?->name,
            'account_active' => (bool) $user->is_active,
            'has_avatar' => (bool) $user->avatar,
            'member_since' => $user->created_at?->toDateString(),
            'editable_fields' => ['full_name', 'phone', 'avatar'] + ($user->role_id === 2 ? ['company_name'] : []),
        ];
    }

    /**
     * Facts needed to hand a problem over to human support. Every field is one the
     * authenticated user is already entitled to see.
     */
    public function supportBundle(User $user, ?Reservation $reservation): array
    {
        return [
            'user_id' => $user->id,
            'account_role' => $user->role?->name ?? $user->role_id,
            'reservation_reference' => $reservation?->reference,
            'reservation_status' => $reservation?->status,
            'property_id' => $reservation?->property_id,
            'payment_reference' => $reservation
                ? $reservation->payments()->orderByDesc('payment_date')->value('transaction_ref')
                : null,
            'contract_exists' => $reservation ? $reservation->contract()->exists() : null,
        ];
    }

    /**
     * "RES-00042" and "42" both mean reservation 42. Anything unparseable is
     * null so it is simply ignored.
     */
    private function referenceToId(string $reference): ?int
    {
        if (preg_match('/(\d+)/', $reference, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
