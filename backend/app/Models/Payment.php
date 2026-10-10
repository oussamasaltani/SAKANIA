<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'payments';

    protected $fillable = [
        'reservation_id',
        'tenant_id',
        'total_amount',
        'amount',
        'paid_amount',
        'remaining_balance',
        'method',
        'status',
        'transaction_ref',
        'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * The property a payment settles, reached through its reservation.
     *
     * `payments` stores no property_id of its own, so this hops
     * payments -> reservations -> properties. Exposing it as a plain
     * `property` keeps the JSON shape the owner-facing screens already
     * read (`payment.property.title`) instead of forcing every caller to
     * dig into `payment.reservation.property`.
     */
    public function property()
    {
        return $this->hasOneThrough(
            Property::class,
            Reservation::class,
            'id',             // reservations.id
            'id',             // properties.id
            'reservation_id', // payments.reservation_id
            'property_id'     // reservations.property_id
        );
    }
}
