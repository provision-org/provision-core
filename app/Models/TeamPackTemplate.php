<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TeamPackTemplate extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'team_pack_templates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_pack_id',
        'agent_template_id',
        'sort_order',
    ];
}
