<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario del panel. Pertenece a **un** tenant.
 *
 * ⚠️ **No usa el trait `BelongsToTenant`, y es deliberado.** El Global Scope de
 * ese trait exige un tenant activo en el contexto, y el login ocurre **antes**
 * de saber de qué tenant es el usuario: la búsqueda por email tiene que poder
 * correr sin contexto. Es la misma excepción que `Integration`, por la misma
 * razón — son los modelos que *resuelven* el tenant, no los que lo consumen.
 *
 * El aislamiento de usuarios se aplica en la autorización, no en el scope.
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // --- Atajos de autorización. La definición vive en el enum `Role`. ---

    public function puedeConfigurar(): bool
    {
        return $this->role?->puedeConfigurar() ?? false;
    }

    public function puedeGestionarUsuarios(): bool
    {
        return $this->role?->puedeGestionarUsuarios() ?? false;
    }

    /**
     * ¿Pertenece a este tenant?
     *
     * Se comprueba **antes** que el rol: un `owner` del tenant A no tiene que
     * llegar nunca a la pregunta de si puede configurar el tenant B.
     */
    public function perteneceA(string $tenantId): bool
    {
        return $this->tenant_id === $tenantId;
    }
}
