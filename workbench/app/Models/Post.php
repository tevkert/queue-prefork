<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** An imported post. Slugged by cviebrock/eloquent-sluggable, logged, indexed through Scout. */
class Post extends Model
{
    use LogsActivity, Searchable, Sluggable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'links' => 'array',
            'is_sensitive' => 'boolean',
            'published_at' => 'datetime',
            'enriched_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function sluggable(): array
    {
        return ['slug' => ['source' => 'title', 'onUpdate' => false, 'maxLength' => 120]];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'excerpt', 'tags', 'is_sensitive'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function searchableAs(): string
    {
        return 'posts';
    }

    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'excerpt' => $this->excerpt];
    }
}
