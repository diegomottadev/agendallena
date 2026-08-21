<?php

namespace App\Support;

/**
 * AC-14.3 · El enlace que abre **ese** evento en Google Calendar.
 *
 * La diferencia entre resolver un reclamo en dos minutos y ponerse a buscar a
 * mano en la agenda: el enlace tiene que abrir el turno del cliente, no el día.
 *
 * Google identifica un evento en la web con el `eid`, que es
 * `base64url(idDelEvento + " " + idDelCalendario)`. Hace falta el calendario
 * además del evento: el mismo id en otra agenda no resuelve a nada.
 *
 * ⚠️ **No guardamos el `htmlLink` que devuelve la API al crear el evento**, así
 * que la URL se arma acá a partir de las dos columnas que sí tenemos. Si algún
 * día se guarda el `htmlLink`, esta clase sobra.
 */
class EnlaceDeCalendario
{
    public static function alEvento(string $eventId, string $calendarId): string
    {
        // Sin `=` de relleno: Google devuelve el `eid` así y el `=` en una query
        // string es un separador, no un dato.
        $eid = rtrim(strtr(base64_encode($eventId.' '.$calendarId), '+/', '-_'), '=');

        return 'https://calendar.google.com/calendar/event?eid='.$eid;
    }
}
