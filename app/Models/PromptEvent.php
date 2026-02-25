<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptEvent extends Model
{
    protected $fillable = [
        'request_uuid',
        'endpoint',
        'event',
        'prompt_id',
        'filters',
        'context',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'context' => 'array',
        ];
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
