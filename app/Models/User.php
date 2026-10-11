<?php

namespace App\Models;

use App\Enums\UserAbility;
use App\Enums\UserRole;
use App\Policies\UserPolicy;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'last_name', 'contact_number', 'email', 'password', 'barangay_id'])]
#[Hidden(['password', 'remember_token'])]
#[Appends(['full_name', 'role_label', 'interface', 'abilities'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
            'role' => UserRole::class,
        ];
    }

    /**
     * Get the barangay this user is assigned to.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Determine if the user holds one of the given roles.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Determine if the user's role grants the given ability.
     */
    public function hasAbility(UserAbility $ability): bool
    {
        return $this->role->hasAbility($ability);
    }

    /**
     * Get the user's full name.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Get the human-readable label for the user's role.
     *
     * @return Attribute<string, never>
     */
    protected function roleLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->role->label());
    }

    /**
     * Get the primary interface the user belongs to.
     *
     * @return Attribute<string, never>
     */
    protected function interface(): Attribute
    {
        return Attribute::get(fn (): string => $this->role->interface()->value);
    }

    /**
     * Get the abilities granted to the user's role.
     *
     * @return Attribute<array<int, string>, never>
     */
    protected function abilities(): Attribute
    {
        return Attribute::get(fn (): array => array_map(
            fn (UserAbility $ability): string => $ability->value,
            $this->role->abilities(),
        ));
    }
}
