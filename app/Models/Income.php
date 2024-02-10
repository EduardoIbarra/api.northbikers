<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'email',
        'customer',
        'amount',
        'fee',
        'total',
        'to_be_transferred',
        'status',
        'stripe_checkout_id',
        'stripe_webhook_email_notification',
        'paid_at',
    ];

    protected $dates = ['deleted_at'];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}