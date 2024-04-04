<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Checkins extends Model
{
    use HasFactory;
    protected $table = 'check_ins';

    public function checkpoint()
    {
        return $this->belongsTo(Checkpoint::class, 'checkpoint_id');
    }
}
