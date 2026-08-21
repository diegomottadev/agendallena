<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversación · AgendaLlena</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-3">
            <div>
                <a href="{{ route('panel') }}" class="text-sm font-semibold text-gray-900">{{ $tenant->name }}</a>
                <span class="ml-2 text-xs text-gray-500">{{ $usuario->name }} · {{ $usuario->role->etiqueta() }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-900">Salir</button>
            </form>
        </div>
    </header>

    {{-- T-034 · AC-25.2 · El aviso de integración caída, en todas las pantallas. --}}
    @include('panel.aviso-integraciones')

    <div class="mx-auto max-w-4xl p-6">
        <a href="{{ route('panel.conversaciones') }}" class="text-xs text-gray-500 hover:text-gray-900">
            ← Volver a las conversaciones
        </a>

        <h1 class="mt-2 text-xl font-semibold text-gray-900">
            {{ $conversacion['user_name'] ?: 'Sin nombre' }}
        </h1>
        <p class="text-sm text-gray-500">{{ $conversacion['user_phone'] }}</p>

        {{--
            AC-14.3 · "¿En qué quedó?" se responde acá, sin volver al listado: en
            qué estado está, cuándo es el turno y cuánto se cotizó.
        --}}
        <dl class="mt-4 grid grid-cols-1 gap-4 rounded-md border border-gray-200 bg-white p-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs uppercase text-gray-500">Estado</dt>
                <dd class="text-gray-900">{{ $conversacion['estado'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-gray-500">Turno</dt>
                <dd class="text-gray-900">
                    {{-- RNF-02 · La hora del negocio, nunca la cruda de la base. --}}
                    {{ $conversacion['turno']['fecha'] ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-gray-500">Cotizado</dt>
                <dd class="text-gray-900">
                    {{--
                        ⚠️ El cotizador está congelado: hoy esto es siempre vacío.
                        Un guion y no un cero — "no se cotizó" no es "cotizó $0".
                    --}}
                    {{ $conversacion['turno']['monto'] ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-gray-500">Última actividad</dt>
                <dd class="text-gray-900">{{ $conversacion['ultima_actividad'] ?? '—' }}</dd>
            </div>
        </dl>

        @if ($conversacion['turno'] && $conversacion['turno']['enlace'])
            {{-- AC-14.3 · El enlace abre **ese** evento, no la agenda del día. --}}
            <a href="{{ $conversacion['turno']['enlace'] }}" target="_blank" rel="noopener"
               class="mt-2 inline-block text-xs text-sky-700 underline hover:text-sky-900">
                Ver el turno en Google Calendar
            </a>
        @endif

        {{--
            AC-21.4 · La marca va **junto al historial** y no en el listado nomás:
            escribirle encima a un compañero que está atendiendo a este cliente a
            mano es el peor error posible en esta pantalla.
        --}}
        @if ($conversacion['pausada'])
            @if ($conversacion['pausa_manual'])
                <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <strong>Pausado desde el panel</strong>
                    <span class="block text-xs">
                        Lo pausó {{ $conversacion['pausada_por'] ?? 'un usuario que ya no está' }}
                        el {{ $conversacion['pausada_desde'] }} ·
                        vuelve solo en {{ $conversacion['minutos_restantes'] }} min
                    </span>
                </div>
            @elseif ($conversacion['pausa_derivacion'])
                {{--
                    T-035 · Se lee al revés que la automática: aquella dice que
                    alguien ya está contestando, esta dice que **nadie contestó
                    todavía** y hay un cliente esperando.
                --}}
                <div class="mt-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                    <strong>Derivada a una persona</strong>
                    <span class="block text-xs">
                        El bot le prometió una persona el {{ $conversacion['pausada_desde'] }} ·
                        vuelve solo en {{ $conversacion['minutos_restantes'] }} min
                    </span>
                </div>
            @else
                {{--
                    Nadie apretó nada: el sistema **dedujo** que alguien contestó
                    por WhatsApp. Leerla igual que la manual lleva a confiar en una
                    deducción como si fuera la decisión de una persona.
                --}}
                <div class="mt-4 rounded-md border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900">
                    <strong>Pausado automáticamente</strong>
                    <span class="block text-xs">
                        Se detectó una respuesta manual el {{ $conversacion['pausada_desde'] }} ·
                        vuelve solo en {{ $conversacion['minutos_restantes'] }} min
                    </span>
                </div>
            @endif
        @endif

        <h2 class="mt-6 text-sm font-semibold text-gray-900">Historial</h2>

        @if ($historial->isEmpty())
            <p class="mt-2 rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Todavía no hay mensajes en esta conversación.
            </p>
        @else
            {{--
                El historial abre en el final y pagina hacia atrás: lo último que
                se dijo es lo que resuelve el reclamo. Los enlaces van arriba
                porque llevan al pasado.
            --}}
            <div class="mt-2">
                {{ $historial->links() }}
            </div>

            <ul class="mt-2 space-y-3">
                @foreach ($historial as $m)
                    {{--
                        AC-14.4 · Entrante y saliente se dibujan distinto: quién
                        dijo qué es la mitad del historial.
                    --}}
                    @if ($m['entrante'])
                        <li class="flex justify-start">
                            <div class="max-w-lg rounded-md rounded-bl-none border border-gray-200 bg-white p-3">
                                <span class="block text-xs font-medium text-gray-500">El cliente</span>
                                <span class="block text-sm text-gray-900">{{ $m['contenido'] }}</span>
                                <span class="block text-xs text-gray-400">{{ $m['cuando'] }}</span>
                            </div>
                        </li>
                    @else
                        <li class="flex justify-end">
                            <div class="max-w-lg rounded-md rounded-br-none border border-emerald-200 bg-emerald-50 p-3 text-right">
                                <span class="block text-xs font-medium text-emerald-700">AgendaLlena</span>
                                <span class="block text-sm text-emerald-950">{{ $m['contenido'] }}</span>
                                <span class="block text-xs text-emerald-600">{{ $m['cuando'] }}</span>
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        {{--
            ⚠️ Responder desde acá queda fuera de alcance a propósito: en el MVP
            el equipo contesta desde WhatsApp, que es donde ya labura.
        --}}
    </div>
</body>
</html>
