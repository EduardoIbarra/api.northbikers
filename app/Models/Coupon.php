<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Coupon extends Model
{
    // Define the table name if it's different from the default 'coupons'
    protected $table = 'coupons';

    // Define the attributes that are mass assignable
    protected $fillable = [
        'code',
        'discount_percentage',
        'max_uses',
        'current_uses',
        'expires_at',
    ];

    // Define the default date fields for Laravel timestamps
    protected $dates = ['created_at', 'updated_at', 'expires_at'];

    /**
     * Check if the coupon is valid.
     *
     * @return bool
     */
    public function isValid()
    {
        // Check if the coupon has not expired and has remaining uses
        return $this->expires_at > Carbon::now() && $this->current_uses < $this->max_uses;
    }

    /**
     * Increment the current uses of the coupon.
     *
     * @return bool
     */
    public function incrementUses()
    {
        // Ensure we are not exceeding the maximum number of uses
        if ($this->current_uses < $this->max_uses) {
            $this->current_uses += 1;
            return $this->save();
        }

        return false;
    }

    /**
     * Check if the coupon has expired.
     *
     * @return bool
     */
    public function hasExpired()
    {
        return $this->expires_at <= Carbon::now();
    }
}
