<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Registra el tenant del piloto con las credenciales de Meta que viven en .env.
 *
 * Existe para que el handshake de T-008 se pueda validar contra el portal real
 * sin que haya todavia pantalla de alta (eso es v2, y esta fuera del alcance del
 * ticket). Idempotente: se puede correr cuantas veces haga falta.
 */
class PilotoMetaSeeder extends Seeder
{
    public function run(): void
    {
        $phoneNumberId = config('services.meta.phone_number_id');
        $verifyToken = config('services.meta.verify_token');

        if (blank($phoneNumberId) || blank($verifyToken)) {
            $this->command?->warn('Faltan META_PHONE_NUMBER_ID o META_VERIFY_TOKEN en .env. No se registro nada.');

            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'piloto'],
            [
                'name' => 'Piloto AgendaLlena',
                'status' => 'trial',
                'timezone' => 'America/Argentina/Buenos_Aires',
            ]
        );

        $integration = Integration::firstOrNew([
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId,
        ]);

        $integration->tenant_id = $tenant->id;
        $integration->settings = ['verify_token' => $verifyToken];
        $integration->access_token = config('services.meta.access_token') ?: null;
        $integration->status = 'connected';
        $integration->save();

        $this->command?->info("Tenant [{$tenant->slug}] listo con el numero {$phoneNumberId}.");
    }
}
