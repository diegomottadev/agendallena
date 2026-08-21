<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    integraciones: { type: Array, default: () => [] },
    tenant: { type: String, default: '' },
    // Solo afecta lo que se muestra. El permiso real lo aplica el middleware
    // `rol:configurar` del servidor: ocultar un boton no es un permiso.
    puedeConfigurar: { type: Boolean, default: false },
});

// Resultado del ida y vuelta con Google, que llega como query string porque el
// callback lo invoca el navegador del cliente y no nuestro panel.
const params = new URLSearchParams(window.location.search);
const resultado = ref(params.get('google'));
const motivo = ref(params.get('motivo'));

const google = computed(
    () => props.integraciones.find((i) => i.provider === 'google_calendar') ?? null,
);
const conectado = computed(() => google.value?.status === 'connected');

/*
 * T-034 · AC-25.1 · Las tres integraciones, con su estado y su cuenta.
 *
 * ⚠️ `sin_configurar` **no es un valor de la base**: es la ausencia de fila
 * traducida por `EstadoDeIntegraciones`. Sin el, "Sheets nunca se conecto" y
 * "Sheets esta sano" se ven identicos —la tarjeta vacia— y en el MVP todos los
 * tenants estan en el primer caso.
 *
 * El punto de color tiene tres estados y no dos: el gris de "nunca se configuro"
 * no puede leerse igual que el rojo de "se rompio y estas perdiendo turnos".
 */
const ESTADOS = {
    connected: { texto: 'Conectada', punto: 'bg-green-500' },
    expired: { texto: 'Se cortó la conexión', punto: 'bg-red-500' },
    disconnected: { texto: 'Desconectada', punto: 'bg-red-500' },
    sin_configurar: { texto: 'Sin configurar', punto: 'bg-gray-300' },
};

const estadoDe = (integracion) => ESTADOS[integracion?.status] ?? ESTADOS.sin_configurar;

// AC-04.3: cancelar deja el indicador en rojo CON una explicación de cómo
// reintentar. Un rojo sin texto obliga a llamar a soporte, que es justo el costo
// que este ticket existe para evitar.
const aviso = computed(() => {
    if (resultado.value === 'conectado') {
        return { tono: 'ok', texto: 'Calendario conectado correctamente.' };
    }
    if (resultado.value === 'cancelado') {
        return {
            tono: 'error',
            texto: 'No se completó la autorización en Google. No se guardó nada: podés volver a intentarlo con el botón de abajo.',
        };
    }
    if (resultado.value === 'error') {
        const detalle = {
            state_invalido: 'El enlace de autorización venció. Empezá de nuevo desde acá.',
            canje_fallido: 'Google rechazó la autorización. Volvé a intentarlo; si sigue fallando, revisá que la cuenta tenga permisos sobre el calendario.',
            tenant_desconocido: 'No se pudo identificar el negocio. Recargá el panel.',
            sin_codigo: 'Google no devolvió la autorización. Volvé a intentarlo.',
        };
        return { tono: 'error', texto: detalle[motivo.value] ?? 'No se pudo conectar el calendario. Volvé a intentarlo.' };
    }
    return null;
});

const urlAutorizar = computed(() => {
    const base = '/api/v1/oauth/google/redirect';
    return props.tenant ? `${base}?tenant=${encodeURIComponent(props.tenant)}` : base;
});
</script>

<template>
    <div class="mx-auto max-w-2xl p-6">
        <h1 class="text-xl font-semibold text-gray-900">Integraciones</h1>
        <p class="mt-1 text-sm text-gray-500">
            Conectá tu calendario para que el bot pueda ofrecer y reservar turnos.
        </p>

        <div
            v-if="aviso"
            class="mt-4 rounded-md p-3 text-sm"
            :class="aviso.tono === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'"
        >
            {{ aviso.texto }}
        </div>

        <!-- Google Calendar -->
        <div class="mt-6 rounded-lg border border-gray-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-block h-2.5 w-2.5 rounded-full"
                            :class="estadoDe(google).punto"
                            :aria-label="estadoDe(google).texto"
                        />
                        <span class="font-medium text-gray-900">Google Calendar</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ estadoDe(google).texto }}
                        <template v-if="google?.account_identifier">
                            · <strong>{{ google.account_identifier }}</strong>
                        </template>
                    </p>
                </div>

                <a
                    v-if="puedeConfigurar"
                    :href="urlAutorizar"
                    class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700"
                >
                    {{ conectado ? 'Reconectar' : 'Conectar Google Calendar' }}
                </a>
                <!-- Staff ve el estado pero no puede tocarlo (H-13). Decirlo es
                     mejor que esconder el botón sin explicación: quien no puede
                     hacerlo tiene que saber a quién pedírselo. -->
                <span v-else class="text-sm text-gray-400">
                    Solo el dueño o un administrador puede conectar
                </span>
            </div>

            <!-- Sin refresh_token la conexión muere sola en una hora y el síntoma
                 aparece al día siguiente, no ahora: conviene avisarlo acá. -->
            <p v-if="conectado && !google.has_refresh_token" class="mt-3 text-sm text-amber-700">
                La conexión no quedó completa: falta el permiso de acceso continuo.
                Usá «Reconectar» y aceptá todos los permisos.
            </p>
        </div>

        <!--
            T-034 · AC-25.1 · El resto de las integraciones, solo lectura: hoy se
            configuran fuera del panel. Se listan **todas** las que no son el
            calendario, incluida la que nunca se conecto: la reconexion
            autoservicio es v2 y lo que este bloque tiene que hacer es que el
            dueno pueda ver de un vistazo que esta en pie y que no.
        -->
        <div
            v-for="integracion in integraciones.filter((i) => i.provider !== 'google_calendar')"
            :key="integracion.provider"
            class="mt-4 rounded-lg border border-gray-200 p-4"
        >
            <div class="flex items-center gap-2">
                <span
                    class="inline-block h-2.5 w-2.5 rounded-full"
                    :class="estadoDe(integracion).punto"
                    :aria-label="estadoDe(integracion).texto"
                />
                <span class="font-medium text-gray-900">{{ integracion.nombre }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                {{ estadoDe(integracion).texto }}
                <template v-if="integracion.account_identifier">
                    · <strong>{{ integracion.account_identifier }}</strong>
                </template>
            </p>
        </div>
    </div>
</template>
