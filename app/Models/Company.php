<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'address', 'location', 'industry', 'contact_number', 'head_name', 'head_contact_number', 'head_email', 'department_head', 'description', 'is_active'])]
class Company extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(CompanySupervisor::class);
    }

    /**
     * The one shared-login "company account" — a company should have at
     * most one company_supervisors row with a user_id set (enforced in
     * CoordinatorCompanyController); oldest() is a stable tie-break if that
     * invariant is ever violated by a fixture/legacy row.
     */
    public function loginSupervisor(): HasOne
    {
        return $this->hasOne(CompanySupervisor::class)->whereNotNull('user_id')->oldest('id');
    }

    public function batchStudents(): HasMany
    {
        return $this->hasMany(BatchStudent::class);
    }
}
