<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_type',
        'external_id',
        'payload',
        'processed',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
    ];
}
