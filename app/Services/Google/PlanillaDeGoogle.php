<?php

namespace App\Services\Google;

use App\Models\Integration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * T-044 / T-045 · El cliente de Google Sheets.
 *
 * Toda llamada pasa por `GoogleConnection`, igual que las de Calendar: el token
 * de acceso dura una hora y renovarlo no es asunto de quien escribe un lead.
 *
 * El scope `spreadsheets` ya viaja en el mismo consentimiento que Calendar
 * (`config/services.php`), así que la PyME que conectó su agenda **ya autorizó
 * esto**: vincular la planilla no la manda de vuelta a la pantalla de Google.
 *
 * ⚠️ **La credencial sale de la integración del tenant**, que es el argumento
 * `$integration`. No hay ninguna variable de entorno global en este archivo, y
 * no puede haberla: escribiría los leads de una PyME con el token de otra.
 */
class PlanillaDeGoogle
{
    private const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const SEGUNDOS_TIMEOUT = 15;

    public function __construct(private readonly GoogleConnection $conexion) {}

    /**
     * Los datos de la planilla, tal cual contesta Google.
     *
     * Se devuelve la respuesta cruda y no un booleano porque **quien vincula
     * necesita distinguir el 403 del 404**: uno se arregla compartiendo el
     * archivo y el otro revisando la URL (AC-26.3). Un `bool` los aplasta en el
     * mismo mensaje y obliga a la dueña a probar las dos cosas.
     *
     * @throws GoogleIntegracionVencida  si hay que reconectar la cuenta.
     */
    public function metadata(Integration $integration, string $spreadsheetId): Response
    {
        $url = self::BASE.'/'.rawurlencode($spreadsheetId)
            .'?fields='.rawurlencode('spreadsheetId,properties.title,sheets.properties.title');

        return $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(self::SEGUNDOS_TIMEOUT)
            ->get($url));
    }

    /**
     * Los nombres de las hojas de una planilla, leídos de su metadata.
     *
     * @return array<int,string>
     */
    public static function hojasDe(Response $metadata): array
    {
        $hojas = [];

        foreach ((array) ($metadata->json('sheets') ?? []) as $hoja) {
            $titulo = $hoja['properties']['title'] ?? null;

            if (is_string($titulo) && $titulo !== '') {
                $hojas[] = $titulo;
            }
        }

        return $hojas;
    }

    /**
     * **AC-26.2.** Crea la planilla y le escribe los encabezados en la misma
     * llamada.
     *
     * Los encabezados van adentro del `spreadsheets.create` y no en un segundo
     * `values.update` a propósito: si la segunda llamada fallara, la PyME
     * quedaría vinculada a una grilla en blanco donde el primer lead son siete
     * datos sueltos que nadie sabe leer.
     *
     * @param  array<int,string>  $encabezados
     * @return string|null  El `spreadsheetId` que creó Google, o `null` si no se
     *                      pudo crear.
     *
     * @throws GoogleIntegracionVencida
     */
    public function crear(Integration $integration, string $titulo, string $hoja, array $encabezados): ?string
    {
        $cuerpo = [
            'properties' => ['title' => $titulo],
            'sheets' => [[
                'properties' => ['title' => $hoja],
                'data' => [[
                    'startRow' => 0,
                    'startColumn' => 0,
                    'rowData' => [[
                        'values' => array_map(
                            fn (string $texto) => ['userEnteredValue' => ['stringValue' => $texto]],
                            $encabezados
                        ),
                    ]],
                ]],
            ]],
        ];

        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(self::SEGUNDOS_TIMEOUT)
            ->post(self::BASE, $cuerpo));

        if (! $respuesta->successful()) {
            Log::error('Google rechazó la creación de la planilla de leads', [
                'tenant_id' => $integration->tenant_id,
                'integracion' => 'google_sheets',
                'codigo' => 'SHEETS_CREACION_RECHAZADA',
                'http' => $respuesta->status(),
            ]);

            return null;
        }

        $id = $respuesta->json('spreadsheetId');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * **AC-13.1.** Agrega el lead al final de la hoja.
     *
     * @param  array<int,string>  $celdas
     * @return int|null  El número de fila que Google le asignó, o `null` si la
     *                   escritura no salió. Esa fila es lo que después permite
     *                   **actualizar** al mismo lead en vez de duplicarlo.
     *
     * @throws GoogleIntegracionVencida
     */
    public function agregarFila(Integration $integration, string $spreadsheetId, string $hoja, array $celdas): ?int
    {
        $url = self::BASE.'/'.rawurlencode($spreadsheetId)
            .'/values/'.rawurlencode($this->rangoDeLaHoja($hoja, count($celdas))).':append'
            // `USER_ENTERED` y no `RAW`: con `RAW` la fecha entra como texto y la
            // PyME no puede ordenar la planilla por ella, que es lo primero que
            // hace cualquiera con una lista de leads.
            .'?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(self::SEGUNDOS_TIMEOUT)
            ->post($url, ['values' => [$celdas]]));

        if (! $respuesta->successful()) {
            $this->registrarRechazo($integration, $respuesta->status(), 'SHEETS_ALTA_RECHAZADA');

            return null;
        }

        return $this->filaDelRango((string) ($respuesta->json('updates.updatedRange') ?? ''));
    }

    /**
     * **AC-13.2.** Reescribe la fila que ya ocupa el lead.
     *
     * @param  array<int,string>  $celdas
     *
     * @throws GoogleIntegracionVencida
     */
    public function actualizarFila(
        Integration $integration,
        string $spreadsheetId,
        string $hoja,
        int $fila,
        array $celdas,
    ): bool {
        $url = self::BASE.'/'.rawurlencode($spreadsheetId)
            .'/values/'.rawurlencode($this->rangoDeUnaFila($hoja, $fila, count($celdas)))
            .'?valueInputOption=USER_ENTERED';

        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(self::SEGUNDOS_TIMEOUT)
            ->put($url, ['values' => [$celdas]]));

        if (! $respuesta->successful()) {
            $this->registrarRechazo($integration, $respuesta->status(), 'SHEETS_ACTUALIZACION_RECHAZADA');

            return false;
        }

        return true;
    }

    /** `'Leads'!A:G` · toda la hoja, para que el `append` busque el final. */
    private function rangoDeLaHoja(string $hoja, int $columnas): string
    {
        return $this->citar($hoja)."!A:{$this->columna($columnas)}";
    }

    /** `'Leads'!A2:G2` · exactamente la fila del lead y ninguna otra. */
    private function rangoDeUnaFila(string $hoja, int $fila, int $columnas): string
    {
        return $this->citar($hoja)."!A{$fila}:{$this->columna($columnas)}{$fila}";
    }

    /**
     * El nombre de la hoja va entre comillas simples porque puede tener espacios
     * —«Hoja 1» es el nombre por defecto en español— y sin comillas Google lo
     * parsea como otra cosa. Una comilla en el nombre se escapa duplicándola,
     * que es la convención de Sheets.
     */
    private function citar(string $hoja): string
    {
        return "'".str_replace("'", "''", $hoja)."'";
    }

    /** La letra de la última columna. Siete columnas son la `G`. */
    private function columna(int $cantidad): string
    {
        return chr(64 + max(1, min(26, $cantidad)));
    }

    /**
     * El número de fila que Google dice haber escrito.
     *
     * Se lee **después del `!`** y no sobre el rango entero: una hoja llamada
     * «Leads2024» daría `2024` como número de fila, y el próximo lead que
     * reserve pisaría una fila a dos mil renglones de distancia.
     */
    private function filaDelRango(string $rango): ?int
    {
        $sinHoja = str_contains($rango, '!')
            ? substr($rango, (int) strrpos($rango, '!') + 1)
            : $rango;

        if (preg_match('/^[A-Z]+(\d+)/', $sinHoja, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function registrarRechazo(Integration $integration, int $http, string $codigo): void
    {
        // RNF-03 · La escritura falló y el cliente no se entera de nada —ni tiene
        // por qué—: el único lugar donde esto puede dejar rastro es acá.
        Log::warning('Google Sheets rechazó la escritura del lead', [
            'tenant_id' => $integration->tenant_id,
            'integracion' => 'google_sheets',
            'codigo' => $codigo,
            'http' => $http,
        ]);
    }
}
