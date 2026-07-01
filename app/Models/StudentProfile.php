<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'address',
        'total_hours_required',
        'student_id_number',
        'middle_name',
        'date_of_birth',
        'sex',
        'contact_number',
        'home_address',
        'year_level',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'total_hours_required' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
