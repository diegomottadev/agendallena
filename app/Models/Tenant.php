<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'status', 'timezone'];

    /**
     * T-014 · Un tenant recien creado tiene configuracion utilizable sin que
     * nadie la cargue.
     *
     * Va en el modelo y no en un seeder porque el criterio es "sin
     * intervencion": si dependiera del seeder, un tenant creado desde el alta
     * del panel (T-046) o desde un test nacería sin horario, y el sintoma seria
     * "el bot no ofrece turnos" en vez de "falta configurar".
     *
     * `withoutTenantScope()` porque `BusinessSetting` filtra por tenant activo y
     * aca todavia no hay ninguno: lo estamos creando en este mismo instante.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            BusinessSetting::withoutTenantScope()->create(
                ['tenant_id' => $tenant->id] + BusinessSetting::valoresPorDefecto()
            );
        });
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function businessSetting(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }
}
