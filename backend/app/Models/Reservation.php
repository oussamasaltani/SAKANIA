<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    /**
     * The reservations table only carries created_at, so Eloquent must not
     * try to maintain an updated_at column on this model.
     */
    public $timestamps = false;

    protected $table = 'reservations';

    protected $fillable = [
        'property_id',
        'tenant_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $appends = [
        'reference',
        'duration_months',
        'contract_id',
        'paid_amount',
        'remaining_balance',
    ];

    /**
     * Stable, human readable reservation reference.
     */
    public function getReferenceAttribute(): string
    {
        return 'RES-' . str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    public function getDurationMonthsAttribute(): int
    {
        return $this->durationInMonths();
    }

    public function getContractIdAttribute(): ?int
    {
        if ($this->relationLoaded('contract')) {
            return $this->getRelation('contract')?->id;
        }

        // Scalar lookup: caching a Contract here would create a circular
        // reference (contract -> reservation -> contract) during toArray().
        return $this->contract()->getQuery()->value('id');
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) ($this->sourcePayment()?->paid_amount ?? 0);
    }

    public function getRemainingBalanceAttribute(): float
    {
        $payment = $this->sourcePayment();

        if ($payment) {
            return (float) $payment->remaining_balance;
        }

        return round($this->totalAmount(), 2);
    }

    /**
     * Most recent payment, read without touching the relation cache.
     *
     * Appended attributes are evaluated while the model is serialised, so this
     * keeps the payments relation out of the graph to avoid building a
     * circular reference (reservation -> payment -> reservation).
     */
    protected function sourcePayment(): ?Payment
    {
        if ($this->relationLoaded('payments')) {
            return $this->getRelation('payments')->sortByDesc('payment_date')->first();
        }

        return $this->payments()->getQuery()->orderByDesc('payment_date')->first();
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Number of rental months covered by this reservation.
     */
    public function durationInMonths(): int
    {
        if (!$this->start_date || !$this->end_date) {
            return 0;
        }

        return max(1, (int) round($this->start_date->diffInMonths($this->end_date)));
    }

    /**
     * Total rental amount for this reservation, using the existing pricing logic.
     */
    public function totalAmount(): float
    {
        $monthlyRate = (float) ($this->property->price_per_month ?? 0);

        return round($monthlyRate * $this->durationInMonths(), 2);
    }

    /**
     * Reservations that hold the property: pending requests and accepted bookings.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'accepted']);
    }

    /**
     * Standard overlap test: an existing booking overlaps when it starts before
     * the requested period ends and ends after the requested period starts.
     */
    public function scopeOverlapping($query, $startDate, $endDate, $exceptId = null)
    {
        return $query
            ->whereDate('start_date', '<', $endDate)
            ->whereDate('end_date', '>', $startDate)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId));
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function contract()
    {
        return $this->hasOne(Contract::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
