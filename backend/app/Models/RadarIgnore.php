<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RadarIgnore extends Model
{
    use HasUuids;

    protected $table = 'radar_ignores';

    protected $fillable = [
        'resource_key',
        'namespace',
        'name',
        'kind',
        'source_type',
        'reason',
    ];
}
