<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agenda · AgendaLlena</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-2xl items-center justify-between px-6 py-3">
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

    <div class="mx-auto max-w-2xl p-6">
        <h1 class="text-xl font-semibold text-gray-900">Agenda</h1>
        <p class="mt-1 text-sm text-gray-500">
            Cuándo atendés y cuánto dura cada turno. El bot solo ofrece horarios dentro de esto.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('panel.configuracion.agenda.guardar') }}" class="mt-6 space-y-6">
            @csrf
            @method('PUT')

            <div>
                <span class="block text-sm font-medium text-gray-700">Días de atención</span>
                <div class="mt-2 flex flex-wrap gap-3">
                    @php
                        $nombres = ['mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mié', 'thu' => 'Jue',
                                    'fri' => 'Vie', 'sat' => 'Sáb', 'sun' => 'Dom'];
                        $seleccionados = old('dias', $dias);
                    @endphp
                    @foreach ($nombres as $clave => $etiqueta)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="dias[]" value="{{ $clave }}"
                                   @checked(in_array($clave, $seleccionados, true))
                                   class="rounded border-gray-300">
                            {{ $etiqueta }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="apertura" class="block text-sm font-medium text-gray-700">Abre</label>
                    <input id="apertura" name="apertura" type="time" required
                           value="{{ old('apertura', $rango[0]) }}"
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="cierre" class="block text-sm font-medium text-gray-700">Cierra</label>
                    <input id="cierre" name="cierre" type="time" required
                           value="{{ old('cierre', $rango[1]) }}"
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                </div>
            </div>

            {{-- El recorte Pareto difiere la jornada partida. Si el tenant la
                 tiene cargada por seeder, guardar desde acá la aplastaría: se
                 avisa en vez de perder datos en silencio. --}}
            @if (app(\App\Http\Controllers\Panel\ConfiguracionAgendaController::class)->tieneJornadaPartida($config))
                <div class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    Tenés cargada una <strong>jornada partida</strong> (mañana y tarde), y esta pantalla
                    todavía maneja un solo rango. Si guardás desde acá, el segundo tramo se pierde.
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="slot_duration_minutes" class="block text-sm font-medium text-gray-700">
                        Duración del turno
                    </label>
                    <div class="mt-1 flex items-center gap-2">
                        <input id="slot_duration_minutes" name="slot_duration_minutes" type="number"
                               min="5" max="480" required
                               value="{{ old('slot_duration_minutes', $config->slot_duration_minutes) }}"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <span class="text-sm text-gray-500">min</span>
                    </div>
                </div>
                <div>
                    <label for="buffer_minutes" class="block text-sm font-medium text-gray-700">
                        Descanso entre turnos
                    </label>
                    <div class="mt-1 flex items-center gap-2">
                        <input id="buffer_minutes" name="buffer_minutes" type="number"
                               min="0" max="240" required
                               value="{{ old('buffer_minutes', $config->buffer_minutes) }}"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <span class="text-sm text-gray-500">min</span>
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-500">
                Los turnos se ofrecen cada <strong>{{ $config->slot_duration_minutes + $config->buffer_minutes }} minutos</strong>
                (duración + descanso). Con la configuración actual, desde las {{ $rango[0] }} saldrían
                {{ $rango[0] }}, {{ \Carbon\CarbonImmutable::parse($rango[0])->addMinutes($config->slot_duration_minutes + $config->buffer_minutes)->format('H:i') }},
                {{ \Carbon\CarbonImmutable::parse($rango[0])->addMinutes(2 * ($config->slot_duration_minutes + $config->buffer_minutes))->format('H:i') }}…
            </p>

            <div>
                <label for="timezone" class="block text-sm font-medium text-gray-700">Zona horaria</label>
                <p class="text-xs text-gray-500">
                    Todos los horarios y recordatorios se muestran en esta zona.
                </p>
                <select id="timezone" name="timezone"
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    @foreach ($zonas as $grupo => $opciones)
                        <optgroup label="{{ $grupo }}">
                            @foreach ($opciones as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(old('timezone', $tenant->timezone) === $valor)>
                                    {{ $etiqueta }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">
                    Ahora son las <strong>{{ now()->setTimezone($tenant->timezone)->format('H:i') }}</strong>
                    en {{ str_replace(['America/', '_'], ['', ' '], $tenant->timezone) }}.
                    Si no coincide con tu reloj, la zona está mal.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">
                    Guardar
                </button>
                <a href="{{ route('panel') }}" class="text-sm text-gray-500 hover:text-gray-900">Cancelar</a>
            </div>
        </form>
    </div>
</body>
</html>
