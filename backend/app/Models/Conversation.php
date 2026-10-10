<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'conversations';

    protected $fillable = [
        'tenant_id',
        'owner_id',
        'property_id',
        'reservation_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The conversation belongs to the tenant who opened it and to the owner of
     * the property it is about, and to nobody else.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query->where('tenant_id', $user->id)->orWhere('owner_id', $user->id);
        });
    }

    /**
     * The other party of the conversation from a given user's point of view.
     */
    public function counterpartFor(User $user): ?User
    {
        $otherId = $user->id === $this->tenant_id ? $this->owner_id : $this->tenant_id;

        return User::find($otherId);
    }

    /**
     * How many messages of this conversation are still unread for the user.
     */
    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('receiver_id', $user->id)
            ->where('is_read', false)
            ->count();
    }
}