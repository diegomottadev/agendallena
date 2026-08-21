<?php

namespace App\Models;

/**
 * Roles del panel — definición completa en
 * `.claude/docs/01-producto/05-roles-y-permisos.md` (cierra H-13).
 *
 * El principio que ordena el reparto, textual de la historia de H-13:
 * *"que mi staff vea los chats pero no toque la configuración ni las
 * integraciones, para delegar la atención sin delegar el control"*.
 *
 * O sea: **atender no es configurar.**
 */
enum Role: string
{
    /** Dueña o dueño. Manda. Único que gestiona usuarios. */
    case Owner = 'owner';

    /**
     * Encargado o socio. Todo lo operativo y la configuración.
     *
     * La única diferencia con `Owner` es quién crea y borra usuarios — pero es
     * la que impide que un encargado que se va se lleve el acceso a la cuenta.
     */
    case Admin = 'admin';

    /** Empleado, vendedor o profesional. Atiende y consulta; no configura. */
    case Staff = 'staff';

    /**
     * ¿Puede tocar configuración del negocio e integraciones?
     *
     * Cubre todo B3 del story map —horarios, duración, buffer, zona horaria,
     * fórmulas, textos— más conectar Google Calendar y vincular la planilla.
     */
    public function puedeConfigurar(): bool
    {
        return $this !== self::Staff;
    }

    /**
     * ¿Puede dar de alta y quitar usuarios?
     *
     * ⚠️ La pantalla es v2 (B1.4). El permiso se define ahora para que la
     * autorización no cambie cuando exista.
     */
    public function puedeGestionarUsuarios(): bool
    {
        return $this === self::Owner;
    }

    /**
     * ¿Puede atender conversaciones y supervisar la operación?
     *
     * Los tres pueden, incluida la pausa del bot: si un empleado está
     * atendiendo a mano y no puede callar al bot, la funcionalidad no sirve.
     */
    public function puedeAtender(): bool
    {
        return true;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Owner => 'Dueño',
            self::Admin => 'Administrador',
            self::Staff => 'Equipo',
        };
    }
}
