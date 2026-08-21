<?php

use App\Console\Commands\ConciliarAgendamientos;
use App\Console\Commands\SincronizarPlantillas;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * T-018b · Expiración por inactividad, cada cinco minutos.
 *
 * El TTL son 30 minutos (T-007) y la tarea corre cada 5: la conversación expira
 * entre los 30 y los 35, que para el cliente es indistinguible. Correrla cada
 * minuto multiplicaría por cinco las consultas para ganar una precisión que a
 * nadie le importa.
 *
 * `withoutOverlapping` porque una corrida lenta —muchas conversaciones, base
 * cargada— no puede solaparse con la siguiente: dos procesos expirando la misma
 * conversación compiten por el lock de la máquina de estados y uno pierde.
 */
Schedule::command('conversaciones:expirar')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * T-037 · Recordatorio interactivo t-24h, cada diez minutos.
 *
 * La cadencia y la ventana son el mismo número: con `t-24h ± 10 min` y una
 * corrida cada 10, ningún turno puede colarse entre dos pasadas. Correrla más
 * espaciada dejaría turnos sin recordatorio, que es el modo de falla que la
 * historia entera existe para evitar.
 *
 * `withoutOverlapping` porque una corrida lenta —muchos turnos, un `GET` a
 * Google por cada uno— no puede solaparse con la siguiente. El candado real
 * contra el duplicado es el único `(booking_id, type)` de `notification_logs`;
 * esto solo evita gastar las dos llamadas.
 */
Schedule::command('recordatorios:enviar')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * T-030 · Conciliación entre el calendario y `bookings`, cada quince minutos.
 *
 * Un comando que existe y que nadie corre no reporta nada, y en producción el
 * síntoma es idéntico al de uno roto: los desalineados se acumulan en silencio.
 *
 * La cadencia la fija `decisiones-tomadas.md` § 7, y es lo mismo que **cuánto
 * tiempo puede un horario quedar ocupado sin turno real detrás**: la contracara
 * de haber elegido conciliar en vez de compensar. Quince minutos es lo que Diego
 * aceptó pagar en llamadas a Google —una por PyME por corrida— a cambio de que
 * el huérfano no sobreviva a la tarde entera.
 *
 * El número **no se escribe acá**: sale de `ConciliarAgendamientos`, junto a las
 * otras constantes de política del comando. Si el piloto muestra que 15 es mucho
 * o poco, se cambia en un solo lugar y el cron lo sigue.
 *
 * `withoutOverlapping` porque una corrida lenta —un listado a Google por cada
 * PyME— no puede solaparse con la siguiente: el mismo desalineado se reportaría
 * dos veces y el log dejaría de servir para contar cuántos hubo.
 */
Schedule::command('agendamientos:conciliar')
    ->cron('*/'.ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS.' * * * *')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * T-050 · Sincronización del estado de las plantillas contra Meta.
 *
 * Meta aprueba de forma asincrónica y **no hay handler del webhook
 * `message_template_status_update`**, así que nada le avisa al sistema cuando la
 * aprobación sale. Sin esta corrida, la PyME registrada en `PENDING` queda
 * trabada hasta que a alguien se le ocurra correr `cuenta:dar-de-alta` a mano —y
 * mientras tanto `EnviarRecordatorios` le frena todo sin ningún error—. Los
 * turnos que cruzan la ventana de t-24h en ese lapso no los recupera nada.
 *
 * El número **no se escribe acá**: sale de `SincronizarPlantillas`, junto a las
 * otras constantes de política del comando.
 *
 * `withoutOverlapping` porque una corrida lenta —una consulta a Meta por cada
 * PyME sin aprobar— no puede solaparse con la siguiente: dos procesos escribiendo
 * el mismo `integrations.settings` se pisan el JSON entre la lectura y el guardado.
 */
Schedule::command('plantillas:sincronizar')
    ->cron('*/'.SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS.' * * * *')
    ->withoutOverlapping()
    ->runInBackground();
