<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SchoolFormTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_code',
        'original_name',
        'stored_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * Human-readable file size (e.g. "124 KB").
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    /**
     * Delete the physical file from storage.
     */
    public function deleteFile(): void
    {
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            Storage::disk('public')->delete($this->file_path);
        }
    }
}