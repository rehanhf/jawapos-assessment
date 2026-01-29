<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reporter extends Model
{
    use HasUuids;
    protected $fillable = ['publisher_id', 'name', 'slug'];
}