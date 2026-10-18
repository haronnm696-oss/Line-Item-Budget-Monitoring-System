<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingAgency extends Model
{
    protected $fillable = [
        'name', 'acronym', 'contact_person', 'contact_email',
    ];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
