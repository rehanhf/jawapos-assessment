<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    //UUID sebagai primary key otomatis
    use HasUuids;

    //kolom yang boleh diisi oleh mass assignment
    protected $fillable = [
        'article_id',
        'user_id',
        'publisher_id',
        'category_id',
        'title',
        'slug',
        'description',
        'content',
        'status',
        'published_at',
        'show_ads',
        'is_public'
    ];

    //relasi polymorphic ke "Reporters"
    //parameter: model target, nama pivot di DB, nama tabel pivot
    public function reporters()
    {
        return $this->morphedByMany(Reporter::class, 'meta', 'article_meta');
    }

    //relasi polymorphic ke "Tags"
    public function tags()
    {
        return $this->morphedByMany(Tag::class, 'meta', 'article_meta');
    }

    //relasi polymorphic ke "Sources"
    public function sources()
    {
        return $this->morphedByMany(Source::class, 'meta', 'article_meta');
    }
}