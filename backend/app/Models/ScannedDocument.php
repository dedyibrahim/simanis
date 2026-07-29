<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScannedDocument extends Model
{
    protected $fillable = [
        'scan_session_id',
        'assistant_user_id',
        'title',
        'original_name',
        'file_name',
        'file_path',
        'mime_type',
        'extension',
        'size_bytes',
        'status',
        'posted_module',
        'posted_record_id',
        'posted_document_id',
        'posted_file_path',
        'posted_by',
        'posted_at',
        'note',
        'uploaded_by_agent',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'scan_session_id');
    }
}
