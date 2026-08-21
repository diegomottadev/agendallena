<?php

namespace App\Models;

use App\Casts\FechaUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    public const PROVIDER_META_WHATSAPP = 'meta_whatsapp';

    public const PROVIDER_GOOGLE_CALENDAR = 'google_calendar';

    public const PROVIDER_GOOGLE_SHEETS = 'google_sheets';

    /**
     * ⚠️ El `provider ENUM` del modelo de datos incluye `outlook_calendar`, pero
     * **no hay constante a proposito**: si Outlook entra al MVP es una decision
     * abierta (T-007) y el C4 no lo contempla. Exponer la constante invita a
     * escribir codigo contra una integracion que quiza no exista.
     */

    protected $fillable = [
        'tenant_id',
        'provider',
        'account_identifier',
        'access_token',
        'refresh_token',
        'expires_at',
        'settings',
        'status',
    ];

    /**
     * `settings` va encriptado en reposo: ahi vive el `verify_token` de Meta.
     * Los tokens de acceso tambien, por RNF de credenciales.
     */
    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => FechaUtc::class,
        ];
    }

    /**
     * Nunca serializar credenciales, ni siquiera por accidente en un log o una
     * respuesta JSON.
     */
    protected $hidden = ['access_token', 'refresh_token', 'settings'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
