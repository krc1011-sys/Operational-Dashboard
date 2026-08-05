<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Enums\UploadType;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * May this user see the order value - units x unit cost - on an operational screen?
     *
     * Yes, for everyone, Warehouse included. Order value is what the marketplace pays us
     * for the units on a line: it is the size of the order, not what we make on it. A
     * warehouse team member reading a shortfall needs to know whether the units missing
     * off a truck are worth 400 or 40,000, because that is what decides whether it is
     * worth chasing. Hiding it made the operational screens harder to act on without
     * protecting anything, since unit cost here is the marketplace's own price and is
     * already printed on every packing list they handle.
     *
     * This is NOT the margin gate, and deliberately so. What we PAY for a product, and
     * therefore the profit on it, is a different number from a different file (the master
     * sheet, §S) and stays Admin-only behind the PIN. The split is: how big is the order
     * - everyone; what do we make on it - Admin only.
     *
     * Kept as a method rather than deleting the checks, so that if this policy is ever
     * narrowed again it is one line here and no screen changes.
     */
    public function canSeeOrderValue(): bool
    {
        return true;
    }

    /**
     * May this user upload anything at all? Decides whether the Uploads tab is shown.
     * At launch this is true for Admin only (blueprint §O).
     */
    public function canUploadAnything(): bool
    {
        foreach (UploadType::cases() as $type) {
            if ($this->can($type->permission())) {
                return true;
            }
        }

        return false;
    }
}
