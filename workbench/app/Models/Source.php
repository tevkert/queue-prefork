<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** A feed source. Slugged by spatie/laravel-sluggable, logged, searchable. */
class Source extends Model
{
    use HasSlug, LogsActivity, Searchable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_imported_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'title', 'description', 'category'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'title' => $this->title];
    }
}
