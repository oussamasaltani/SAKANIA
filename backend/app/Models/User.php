<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $timestamps = false;

    protected $table = 'users';

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'phone',
        'role_id',
        'is_active',
        'avatar',
        'company_name',
        'response_rate',
        'google_id',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
        // Google's subject id: how this account is recognised on the next Google
        // sign-in. It has no business travelling with the user.
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            // Null for an account that only ever signs in with Google, which is
            // exactly what makes "no password matches" true for it.
            'password_hash' => 'hashed',
            'is_active' => 'boolean',
            'response_rate' => 'decimal:2',
            'created_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Whether this account has a password of its own, as opposed to only ever
     * signing in with Google. The profile form needs it to know if the current
     * password has to be asked for.
     */
    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /**
     * Whether this account is linked to a Google identity.
     */
    public function hasGoogleAccount(): bool
    {
        return $this->google_id !== null;
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'owner_id');
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function reservationsAsTenant()
    {
        return $this->hasMany(Reservation::class, 'tenant_id');
    }

    public function contractsAsOwner()
    {
        return $this->hasMany(Contract::class, 'owner_id');
    }

    public function contractsAsTenant()
    {
        return $this->hasMany(Contract::class, 'tenant_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'tenant_id');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    /**
     * Both parties of a conversation sit on the same row (tenant_id and
     * owner_id), so they are two plain relations rather than a pivot table.
     */
    public function conversationsAsTenant()
    {
        return $this->hasMany(Conversation::class, 'tenant_id');
    }

    public function conversationsAsOwner()
    {
        return $this->hasMany(Conversation::class, 'owner_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function chatbotConversations()
    {
        return $this->hasMany(ChatbotConversation::class);
    }
}
