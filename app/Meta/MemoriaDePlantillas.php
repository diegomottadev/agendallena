<?php

namespace App\Meta;

/**
 * Lo que ya se leyó de `integrations.settings` **en esta corrida**.
 *
 * Existe por el N+1 sistémico de `PlantillasDelTenant`: cuatro de sus métodos
 * releen la integración, y `EnviarRecordatorios` le pregunta dos cosas a la
 * misma cuenta —«¿están todas aprobadas?» y «¿cuáles faltan?»— por **cada
 * turno**, cada diez minutos, sobre toda la cartera. Es el mismo estado
 * preguntado dos veces y pagado dos veces.
 *
 * ## Por qué esto no rompe el alta, que es lo que `recargar()` protegía
 *
 * ⚠️ **No es una caché.** No tiene TTL, no tiene almacén compartido y nadie la
 * consulta desde otro proceso. Dos cosas la mantienen fresca:
 *
 * 1. **`PlantillasDelTenant::registrar()` la actualiza al escribir.** El caso que
 *    el docblock de `recargar()` nombra —el alta corre aparte y quien consulta
 *    tiene en la mano una instancia cargada antes— se resuelve igual que antes:
 *    la memoria es por **id de integración**, no por instancia, así que lo que
 *    escribe el alta lo ve la instancia vieja en la misma corrida.
 * 2. **Muere con la aplicación.** Se resuelve del contenedor y no de una
 *    propiedad estática justamente por eso: una corrida de `artisan` la crea al
 *    arrancar y la tira al terminar, así que el proceso siguiente vuelve a leer
 *    de la base. El dato nunca queda viejo *para siempre*; queda quieto los
 *    segundos que dura la tarea, que es lo que ya duraba antes entre dos turnos.
 *
 * ## ⚠️ El límite, y qué pasa exactamente el día que se pise
 *
 * Lo único que separa esto del mecanismo que **rompe el alta en silencio** es que
 * el proceso muere. Hoy se cumple: `PlantillasDelTenant` se llama solo desde
 * comandos de `artisan` —verificado, no hay ninguna llamada desde `app/Jobs/`,
 * `app/Recordatorios/` ni `app/Http/`— y cada corrida arranca con la memoria
 * vacía.
 *
 * **El día que alguien llame a `PlantillasDelTenant` desde un job encolado, deja
 * de cumplirse.** `queue:work` **no reconstruye el contenedor entre trabajos**:
 * el worker leería el estado una vez, lo guardaría acá y no volvería a preguntar
 * mientras siga vivo. Entonces la PyME que Meta acaba de aprobar queda en
 * `PENDING` **indefinidamente** para ese worker: `todasAprobadas()` sigue
 * contestando `false`, `EnviarRecordatorios` frena el envío, y **no le sale
 * ningún recordatorio a ningún cliente de esa PyME**. Sin excepción, sin
 * rechazo de Meta y sin una línea de log que lo nombre — el fallo silencioso que
 * T-050 vino a cerrar, reintroducido por una optimización. El síntoma que llega
 * es «a los clientes de esta peluquería no les avisa nadie», y no apunta acá.
 *
 * ⚠️ **Próximo ciclo rojo:** vaciarla en el evento `JobProcessing`. Eso vuelve el
 * límite imposible de pisar en vez de solo documentado, que es lo que hoy es.
 */
class MemoriaDePlantillas
{
    /** @var array<string,array<string,mixed>> Los `settings`, por id de integración. */
    private array $settings = [];

    public function tiene(int|string $id): bool
    {
        return array_key_exists((string) $id, $this->settings);
    }

    /** @return array<string,mixed> */
    public function leer(int|string $id): array
    {
        return $this->settings[(string) $id] ?? [];
    }

    /** @param  array<string,mixed>  $settings */
    public function recordar(int|string $id, array $settings): void
    {
        $this->settings[(string) $id] = $settings;
    }
}
