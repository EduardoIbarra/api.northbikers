<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventProfile extends Model
{
    use HasFactory;
    protected $table = 'event_profile';
    protected $fillable = [
        'profile_id',
        'route_id',
        'current_lat',
        'current_lng',
        'points',
        'participant_number',
        'category',
        'device_uuid',
        'device_model',
        'gender',
        'name_on_jersey',
        'full_name',
        'jersey_size',
        'motorcycle',
        'city',
        'birthday',
        'status',
        'stripe_checkout_id',
        'payment_status',
        'stripe_webhook_email_notification',
    ];

    // Relationship with Profile
    public function profile()
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    // Relationship with Event (assuming Route model represents an event)
    public function event()
    {
        return $this->belongsTo(Route::class, 'route_id');
    }
}