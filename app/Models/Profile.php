<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $table = 'profiles';
    protected $fillable = [
        'raw_user_meta_data',
        'is_super_admin',
        'phone_confirmed_at',
        'instance_id',
        'email_confirmed_at',
        'phone_change_sent_at',
        'confirmed_at',
        'invited_at',
        'email_change_confirm_status',
        'banned_until',
        'reauthentication_sent_at',
        'is_sso_user',
        'confirmation_sent_at',
        'email_verified_at',
        'recovery_sent_at',
        'last_sign_in_at',
        'raw_app_meta_data',
        'email_change',
        'name',
        'email',
        'password',
        'remember_token',
        'aud',
        'role',
        'encrypted_password',
        'confirmation_token',
        'recovery_token',
        'email_change_token_new',
        'phone',
        'phone_change',
        'phone_change_token',
        'email_change_token_current',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'phone_confirmed_at',
        'email_confirmed_at',
        'phone_change_sent_at',
        'confirmed_at',
        'invited_at',
        'banned_until',
        'reauthentication_sent_at',
        'confirmation_sent_at',
        'email_verified_at',
        'recovery_sent_at',
        'last_sign_in_at',
    ];
}
