<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Route extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'id',
        'customer_id',
        'rally',
        'featured',
        'pinned',
        'show_points',
        'start_timestamp',
        'end_timestamp',
        'cover',
        'active',
        'profile_id',
        'title',
        'description',
        'video_id',
        'rules_file',
        'banner',
        'amount',
    ];

    protected $dates = ['deleted_at'];

    // Relationship with EventProfile
    public function eventProfiles()
    {
        return $this->hasMany(EventProfile::class, 'route_id');
    }
}
