<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * T-044 · La planilla de Google Sheets que la PyME eligió para sus leads.
 *
 * Una fila por tenant, garantizado por el único de `tenant_id`. Y una planilla
 * por negocio en toda la plataforma, garantizado por el único de
 * `spreadsheet_id` — decisión § 11, que no es higiene: dos PyMEs sobre el mismo
 * archivo se ven los clientes entre sí (RNF-01).
 *
 * Los dos candados son del esquema. Este modelo no los repite en PHP: una
 * comprobación acá dejaría la ventana de carrera abierta entre dos workers y
 * daría la falsa sensación de que el candado está puesto.
 */
class LeadSpreadsheet extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'spreadsheet_id',
        'sheet_name',
    ];
}
