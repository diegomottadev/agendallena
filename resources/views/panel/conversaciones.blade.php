<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversaciones · AgendaLlena</title>
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
        <h1 class="text-xl font-semibold text-gray-900">Conversaciones</h1>
        <p class="mt-1 text-sm text-gray-500">
            Pausar el bot lo calla <strong>solo para ese cliente</strong> durante {{ \App\Conversacion\Pausa::MINUTOS }} minutos.
            Al reactivarlo retoma desde donde había quedado.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        {{--
            T-035 · AC-20.2 · Los pendientes de atender, arriba de todo y con el
            más viejo primero. Van separados del listado general a propósito: son
            clientes a los que el bot **ya les prometió** que los iba a atender
            una persona, y mezclarlos con el resto los volvería invisibles.
        --}}
        @if (! empty($derivaciones))
            <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 p-4">
                <h2 class="text-sm font-semibold text-amber-900">
                    Esperando que los atienda una persona ({{ count($derivaciones) }})
                </h2>
                <p class="mt-1 text-xs text-amber-800">
                    El bot ya les dijo que alguien del equipo les va a escribir. Contestales por WhatsApp
                    y después marcá la derivación como resuelta para que el bot vuelva a atenderlos.
                </p>

                <ul class="mt-3 divide-y divide-amber-200">
                    @foreach ($derivaciones as $d)
                        <li class="flex items-center justify-between gap-4 py-2">
                            <div>
                                <span class="text-sm font-medium text-gray-900">{{ $d['user_name'] ?: 'Sin nombre' }}</span>
                                <span class="text-xs text-gray-600">· {{ $d['user_phone'] }}</span>
                                <span class="block text-xs text-gray-600">
                                    {{ $d['motivo'] }} · derivada el {{ $d['derivada_el'] }} ({{ $d['esperando_hace'] }})
                                </span>
                            </div>
                            <form method="POST" action="{{ route('panel.conversaciones.resolver', $d['id']) }}">
                                @csrf
                                <button type="submit"
                                        class="rounded-md bg-amber-900 px-3 py-2 text-xs font-medium text-white hover:bg-amber-800">
                                    Marcar como atendida
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($conversaciones->isEmpty())
            <p class="mt-6 rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Todavía no hay conversaciones. Aparecen acá en cuanto alguien le escriba a tu WhatsApp.
            </p>
        @else
            <div class="mt-6 overflow-hidden rounded-md border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Cliente</th>
                            <th class="px-4 py-2 font-medium">Estado</th>
                            {{-- T-047 · AC-14.1 · Cuándo es el turno y cuánto se cotizó. --}}
                            <th class="px-4 py-2 font-medium">Turno</th>
                            <th class="px-4 py-2 font-medium">Cotizado</th>
                            <th class="px-4 py-2 font-medium">Última actividad</th>
                            <th class="px-4 py-2 font-medium">Bot</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($conversaciones as $c)
                            <tr>
                                <td class="px-4 py-3">
                                    {{-- T-049 · Desde acá se abre el historial completo. --}}
                                    <a href="{{ route('panel.conversaciones.detalle', $c['id']) }}"
                                       class="font-medium text-gray-900 underline decoration-gray-300 hover:decoration-gray-900">
                                        {{ $c['user_name'] ?: 'Sin nombre' }}
                                    </a>
                                    <span class="block text-xs text-gray-500">{{ $c['user_phone'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $c['estado'] }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    @if ($c['turno'])
                                        {{-- RNF-02 · La hora del negocio, nunca la de la base. --}}
                                        <span class="block">{{ $c['turno']['fecha'] }}</span>
                                        @if ($c['turno']['enlace'])
                                            {{--
                                                AC-14.3 · El enlace abre **ese** evento. Sin turno no
                                                se dibuja: un enlace roto es peor que ninguno.
                                            --}}
                                            <a href="{{ $c['turno']['enlace'] }}" target="_blank" rel="noopener"
                                               class="text-xs text-sky-700 underline hover:text-sky-900">
                                                Ver en Google Calendar
                                            </a>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{--
                                        ⚠️ El cotizador está congelado: hoy esto es siempre vacío.
                                        Un guion y no un cero — "no se cotizó" no es "cotizó $0".
                                    --}}
                                    {{ $c['turno']['monto'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $c['ultima_actividad'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if (! $c['pausada'])
                                        <span class="rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-800">
                                            Respondiendo
                                        </span>
                                    @elseif ($c['pausa_manual'])
                                        {{--
                                            AC-21.4 · La pausa del panel tiene un responsable con
                                            nombre; la automática no. Se ven distinto a propósito:
                                            saber si la pausa la puso alguien del equipo o la detectó
                                            el sistema cambia qué se hace con ella.
                                        --}}
                                        <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-900">
                                            Pausado desde el panel
                                        </span>
                                        <span class="mt-1 block text-xs text-gray-600">
                                            Lo pausó {{ $c['pausada_por'] ?? 'un usuario que ya no está' }}
                                            el {{ $c['pausada_desde'] }}
                                        </span>
                                    @elseif ($c['pausa_derivacion'])
                                        {{--
                                            T-035 · Se lee al revés que la automática: aquella dice
                                            que alguien ya está contestando, esta dice que **nadie
                                            contestó todavía** y hay un cliente esperando.
                                        --}}
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-900">
                                            Derivada a una persona
                                        </span>
                                        <span class="mt-1 block text-xs text-gray-600">
                                            El bot le prometió una persona el {{ $c['pausada_desde'] }}
                                        </span>
                                    @else
                                        <span class="rounded-full bg-sky-100 px-2 py-1 text-xs font-medium text-sky-900">
                                            Pausado automáticamente
                                        </span>
                                        <span class="mt-1 block text-xs text-gray-600">
                                            Se detectó una respuesta manual el {{ $c['pausada_desde'] }}
                                        </span>
                                    @endif

                                    @if ($c['pausada'])
                                        {{-- AC-21.1 · El tiempo restante, a la vista. --}}
                                        <span class="mt-1 block text-xs text-gray-500">
                                            Vuelve solo en {{ $c['minutos_restantes'] }} min
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($c['pausada'])
                                        <form method="POST" action="{{ route('panel.conversaciones.reactivar', $c['id']) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-700">
                                                Reactivar el bot
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('panel.conversaciones.pausar', $c['id']) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                Pausar el bot
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{--
                T-047 · El paginado que la pantalla mínima de T-025 no tenía: con
                un límite fijo, a partir de la fila 51 el cliente no existía para
                el dueño y no había forma de llegar a él.
            --}}
            <div class="mt-4">
                {{ $conversaciones->links() }}
            </div>
        @endif
    </div>
</body>
</html>
