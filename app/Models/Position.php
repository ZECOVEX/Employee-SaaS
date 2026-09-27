<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'department_id', 'title'])]
class Position extends Model
{
    use BelongsToOrganization;

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
