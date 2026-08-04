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
     * May this user see money at all - any AED figure on an operational screen?
     *
     * §O gives three money lenses (margin, buy price, sell price) and says Warehouse
     * gets none of them. The operational screens show one kind of money that fits none
     * of the three neatly: the marketplace value of units, which is what shortfall and
     * invoice totals are made of. Rather than guess which lens that is, any of the three
     * unlocks it, which lands exactly where §O does - Warehouse sees units only.
     *
     * This is NOT the margin/P&L gate. Those screens are Admin-only and behind the PIN
     * as well (§S), and arrive at M7.
     */
    public function canSeeMoney(): bool
    {
        return $this->canAny(['view-margin', 'view-sku-cost', 'view-sku-price']);
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
