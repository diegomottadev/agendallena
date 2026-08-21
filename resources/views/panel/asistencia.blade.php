<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asistencia · AgendaLlena</title>
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
        <h1 class="text-xl font-semibold text-gray-900">Asistencia</h1>
        <p class="mt-1 text-sm text-gray-500">
            Marcar quién vino y quién no es lo que permite saber si los recordatorios sirven.
            Se marca de a un clic; el estado del turno no se toca.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if (session('error'))
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        {{-- AC-17.2 · Los pasados sin marcar, sin tener que buscarlos. --}}
        <h2 class="mt-6 text-sm font-semibold text-gray-900">
            Pendientes de marcar ({{ count($pendientes) }})
        </h2>

        @if (empty($pendientes))
            <p class="mt-2 rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                No queda ningún turno pasado sin marcar. Al día.
            </p>
        @else
            <div class="mt-2 overflow-hidden rounded-md border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Cliente</th>
                            <th class="px-4 py-2 font-medium">Turno</th>
                            <th class="px-4 py-2 font-medium text-right">¿Vino?</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($pendientes as $p)
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ $p['client_name'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $p['service_name'] }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-700">{{ $p['cuando'] }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-end gap-2">
                                        {{-- Un clic por respuesta: sin pantalla intermedia (AC-17.1). --}}
                                        <form method="POST" action="{{ route('panel.asistencia.marcar', $p['id']) }}">
                                            @csrf
                                            <input type="hidden" name="asistencia" value="attended">
                                            <button type="submit"
                                                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-700">
                                                Vino
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('panel.asistencia.marcar', $p['id']) }}">
                                            @csrf
                                            <input type="hidden" name="asistencia" value="no_show">
                                            <button type="submit"
                                                    class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                No vino
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- AC-17.3 · La tasa por rango: el KPI del lean canvas. --}}
        <h2 class="mt-8 text-sm font-semibold text-gray-900">Tasa de ausentismo</h2>

        <form method="GET" action="{{ route('panel.asistencia') }}" class="mt-2 flex flex-wrap items-end gap-3">
            <label class="text-xs text-gray-600">
                Desde
                <input type="date" name="desde" value="{{ $desde }}"
                       class="mt-1 block rounded-md border border-gray-300 px-3 py-2 text-sm">
            </label>
            <label class="text-xs text-gray-600">
                Hasta
                <input type="date" name="hasta" value="{{ $hasta }}"
                       class="mt-1 block rounded-md border border-gray-300 px-3 py-2 text-sm">
            </label>
            <button type="submit"
                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-700">
                Ver
            </button>
        </form>

        @if ($resumen === null)
            <p class="mt-2 text-sm text-gray-500">Elegí un rango de fechas para ver el resumen.</p>
        @else
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-md border border-gray-200 bg-white p-4">
                    <span class="block text-xs text-gray-500">Turnos</span>
                    <span class="text-lg font-semibold text-gray-900">{{ $resumen['turnos'] }}</span>
                </div>
                <div class="rounded-md border border-gray-200 bg-white p-4">
                    <span class="block text-xs text-gray-500">Asistidos</span>
                    <span class="text-lg font-semibold text-gray-900">{{ $resumen['asistidos'] }}</span>
                </div>
                <div class="rounded-md border border-gray-200 bg-white p-4">
                    <span class="block text-xs text-gray-500">Ausentes</span>
                    <span class="text-lg font-semibold text-gray-900">{{ $resumen['ausentes'] }}</span>
                </div>
                <div class="rounded-md border border-gray-200 bg-white p-4">
                    <span class="block text-xs text-gray-500">Ausentismo</span>
                    <span class="text-lg font-semibold text-gray-900">{{ $resumen['tasa_ausentismo'] }}%</span>
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                La tasa se calcula sobre los {{ $resumen['marcados'] }} turnos marcados del rango.
                Los que quedaron sin marcar no cuentan: son datos que no tenemos, no asistencias.
            </p>

            {{-- AC-17.4 · El cruce con la respuesta al recordatorio. --}}
            @if (! empty($marcados))
                <div class="mt-4 overflow-hidden rounded-md border border-gray-200 bg-white">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Cliente</th>
                                <th class="px-4 py-2 font-medium">Turno</th>
                                <th class="px-4 py-2 font-medium">Asistencia</th>
                                <th class="px-4 py-2 font-medium">Recordatorio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($marcados as $m)
                                <tr>
                                    <td class="px-4 py-2">
                                        <span class="font-medium text-gray-900">{{ $m['client_name'] }}</span>
                                        <span class="block text-xs text-gray-500">{{ $m['service_name'] }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ $m['cuando'] }}</td>
                                    <td class="px-4 py-2 text-gray-700">
                                        {{ $m['attendance'] === 'attended' ? 'Vino' : 'No vino' }}
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">
                                        @switch($m['respuesta_recordatorio'])
                                            @case('confirmado') Había confirmado @break
                                            @case('sin_respuesta') No contestó @break
                                            @default No se le mandó recordatorio
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</body>
</html>
