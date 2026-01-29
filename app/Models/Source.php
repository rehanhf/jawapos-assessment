<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    use HasUuids;
    protected $fillable = ['publisher_id', 'name', 'alias', 'url'];
}