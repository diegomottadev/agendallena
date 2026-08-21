<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Turnos en duda · AgendaLlena</title>
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
        <h1 class="text-xl font-semibold text-gray-900">Turnos en duda</h1>
        <p class="mt-1 text-sm text-gray-500">
            Turnos que <strong>siguen agendados acá</strong> pero cuyo evento ya no está en tu Google Calendar.
            Alguien lo borró de ahí y el sistema no decide por vos: <strong>el cliente no recibió ningún aviso</strong>.
            Si hace falta hablar con él, escribile desde el número de atención.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if (session('error'))
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        @if (empty($hallazgos))
            <p class="mt-6 rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Nada en duda. Tu agenda y tu Google Calendar dicen lo mismo.
            </p>
        @else
            <div class="mt-6 overflow-hidden rounded-md border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Cliente</th>
                            <th class="px-4 py-2 font-medium">Turno</th>
                            <th class="px-4 py-2 font-medium">Detectado</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($hallazgos as $h)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900">{{ $h['client_name'] ?: 'Sin nombre' }}</span>
                                    <span class="block text-xs text-gray-500">{{ $h['client_phone'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ $h['service_name'] }}
                                    {{-- RNF-02 · En la hora del negocio, nunca en UTC. --}}
                                    <span class="block text-xs text-gray-500">{{ $h['cuando'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $h['detectado'] }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        {{-- Mantener no recrea el evento en Google: el turno queda
                                             vivo acá y ausente del calendario, y eso se dice. --}}
                                        <form method="POST"
                                              action="{{ route('panel.turnos-en-duda.resolver', $h['id']) }}">
                                            @csrf
                                            <input type="hidden" name="resolucion" value="mantener">
                                            <button type="submit"
                                                    class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-700">
                                                El turno va
                                            </button>
                                        </form>
                                        <form method="POST"
                                              action="{{ route('panel.turnos-en-duda.resolver', $h['id']) }}">
                                            @csrf
                                            <input type="hidden" name="resolucion" value="cancelar">
                                            <button type="submit"
                                                    class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-100">
                                                Cancelarlo
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
    </div>
</body>
</html>
