<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function approvedProductStagingRows(): HasMany
    {
        return $this->hasMany(ProductStagingRow::class, 'approved_by_id');
    }

    public function createdNormalizationRules(): HasMany
    {
        return $this->hasMany(NormalizationRule::class, 'created_by_id');
    }

    public function updatedNormalizationRules(): HasMany
    {
        return $this->hasMany(NormalizationRule::class, 'updated_by_id');
    }

    public function reviewedNormalizationSuggestions(): HasMany
    {
        return $this->hasMany(NormalizationSuggestion::class, 'reviewed_by_id');
    }

    public function productChangeLogs(): HasMany
    {
        return $this->hasMany(ProductChangeLog::class, 'changed_by_id');
    }
}
