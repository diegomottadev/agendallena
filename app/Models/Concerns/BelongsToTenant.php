<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aislamiento por `tenant_id` (RNF-01) como Global Scope.
 *
 * ## Por que falla ruidoso
 *
 * Sin tenant activo, este scope **lanza** en vez de no filtrar. La alternativa
 * habitual —no aplicar el filtro cuando no hay contexto— convierte el scope en
 * decoracion: la consulta devuelve las filas de todas las PyMEs y nadie se
 * entera, que es literalmente la falla que RNF-01 existe para impedir. Un error
 * en desarrollo es infinitamente mas barato que un dato cruzado en produccion.
 *
 * Para los casos que son legitimamente cross-tenant esta `withoutTenantScope()`,
 * que obliga a escribir la intencion.
 *
 * ⚠️ **No se aplica a `Integration`, y es deliberado:** la ingesta resuelve el
 * tenant *a partir* del `phone_number_id` que manda Meta, o sea que consulta
 * antes de saber de que tenant se trata. Es la excepcion que justifica el unico
 * `(provider, account_identifier)`.
 *
 * ⚠️ **`User` tampoco lo aplica todavia:** lo necesita recien con la
 * autenticacion del panel (T-046), que es quien va a poblar el contexto.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                TenantContext::idOrFail()
            );
        });

        // Al crear, el tenant sale del contexto y no de quien escribe el codigo:
        // un `create()` que se olvide de `tenant_id` no puede quedar huerfano ni,
        // peor, caer en el tenant equivocado.
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::idOrFail();
            }
        });
    }

    /**
     * Escotilla explicita para operaciones legitimamente cross-tenant:
     * migraciones de datos, reportes de plataforma, y la resolucion de tenant
     * de la propia ingesta.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope('tenant');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
