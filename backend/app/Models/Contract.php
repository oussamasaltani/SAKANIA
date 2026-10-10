<?php

namespace App\Models;

use App\Services\ContractService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;

    /**
     * The contracts table only carries created_at, so Eloquent must not try to
     * maintain an updated_at column on this model.
     */
    public $timestamps = false;

    protected $table = 'contracts';

    protected $fillable = [
        'reservation_id',
        'owner_id',
        'tenant_id',
        'file_url',
        'signed_at',
        'status',
    ];

    /**
     * Reservation/property/financial details are derived from the related
     * records so both dashboards can display real stored data.
     */
    protected $appends = [
        'property',
        'start_date',
        'end_date',
        'duration',
        'duration_months',
        'monthly_rent',
        'total_amount',
        'paid_amount',
        'remaining_balance',
        'payment_status',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'reservation_id', 'reservation_id');
    }

    public function latestPayment()
    {
        return $this->hasOne(Payment::class, 'reservation_id', 'reservation_id')
            ->latestOfMany('payment_date');
    }

    /**
     * Source reservation, read without touching the relation cache.
     *
     * Appended attributes are evaluated while the model is serialised, so
     * caching the reservation here would build a circular reference
     * (contract -> reservation -> contract) and blow up json_encode().
     */
    protected function sourceReservation(): ?Reservation
    {
        if ($this->relationLoaded('reservation')) {
            return $this->getRelation('reservation');
        }

        return $this->reservation()->getQuery()->with('property')->first();
    }

    /**
     * Latest payment, read without touching the relation cache.
     */
    protected function sourcePayment(): ?Payment
    {
        if ($this->relationLoaded('latestPayment')) {
            return $this->getRelation('latestPayment');
        }

        return $this->latestPayment()->getQuery()->first();
    }

    public function getPropertyAttribute()
    {
        return $this->sourceReservation()?->property;
    }

    public function getStartDateAttribute()
    {
        return $this->sourceReservation()?->start_date;
    }

    public function getEndDateAttribute()
    {
        return $this->sourceReservation()?->end_date;
    }

    public function getDurationAttribute()
    {
        return $this->sourceReservation()?->durationInMonths();
    }

    public function getDurationMonthsAttribute()
    {
        return $this->getDurationAttribute();
    }

    public function getMonthlyRentAttribute()
    {
        return $this->sourceReservation()?->property?->price_per_month;
    }

    public function getTotalAmountAttribute()
    {
        return $this->sourcePayment()?->total_amount;
    }

    public function getPaidAmountAttribute()
    {
        return $this->sourcePayment()?->paid_amount;
    }

    public function getRemainingBalanceAttribute()
    {
        return $this->sourcePayment()?->remaining_balance;
    }

    public function getPaymentStatusAttribute()
    {
        return $this->sourcePayment()?->status;
    }

    /**
     * Stable, human readable contract reference built from real stored data.
     */
    public function getReferenceAttribute()
    {
        return 'SC-' . str_pad((string) $this->reservation_id, 5, '0', STR_PAD_LEFT);
    }

    public function isDownloadable(): bool
    {
        return app(ContractService::class)->exists($this);
    }
}
