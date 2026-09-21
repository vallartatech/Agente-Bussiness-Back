<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrabajoChat extends Model
{
    protected $fillable = [
        'trabajo_id',
        'canal',
        'sender_id',
        'message',
        'is_quote',
        'quote_amount'
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
