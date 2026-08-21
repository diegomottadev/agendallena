<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recordatorios que no salieron · AgendaLlena</title>
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

    @include('panel.aviso-integraciones')

    <div class="mx-auto max-w-4xl p-6">
        <h1 class="text-xl font-semibold text-gray-900">Recordatorios que no salieron</h1>
        <p class="mt-1 text-sm text-gray-500">
            Turnos que <strong>todavía no pasaron</strong> y cuyo recordatorio no le llegó al cliente.
            El sistema no los reintenta solo, así que o los reintentás desde acá o llamás al cliente.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if (session('error'))
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        @if (empty($fallidos))
            <p class="mt-6 rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Ningún recordatorio pendiente de resolver. Todos los turnos que vienen tienen su aviso.
            </p>
        @else
            <div class="mt-6 overflow-hidden rounded-md border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Cliente</th>
                            <th class="px-4 py-2 font-medium">Turno</th>
                            <th class="px-4 py-2 font-medium">Falta</th>
                            <th class="px-4 py-2 font-medium">Por qué no salió</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($fallidos as $f)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900">{{ $f['client_name'] ?: 'Sin nombre' }}</span>
                                    <span class="block text-xs text-gray-500">{{ $f['client_phone'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ $f['service_name'] }}
                                    {{-- RNF-02 · En la hora del negocio, nunca en UTC. --}}
                                    <span class="block text-xs text-gray-500">{{ $f['cuando'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $f['tiempo_restante'] }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $f['motivo'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($f['se_puede_reintentar'])
                                        <form method="POST"
                                              action="{{ route('panel.recordatorios-fallidos.reintentar', $f['notification_log_id']) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-700">
                                                Reintentar
                                            </button>
                                        </form>
                                    @else
                                        {{--
                                            AC-24.4 · Un «recordatorio de mañana» mandado a esta
                                            altura confunde más de lo que ayuda: el cliente cree
                                            que el turno es otro día. Se dice por qué no hay botón.
                                        --}}
                                        <span class="text-xs text-gray-500">
                                            Falta menos de {{ $horas_minimas }} h: mejor llamalo
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</body>
</html>
