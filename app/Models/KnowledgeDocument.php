<?php

namespace App\Models;

use App\Http\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeDocument extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'original_filename',
        'file_path',
        'file_type',
        'file_size',
        'module',
        'openai_file_id',
        'status',
        'notes',
        'uploaded_by',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
