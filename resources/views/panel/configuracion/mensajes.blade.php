<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mensajes del bot · AgendaLlena</title>
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
        <h1 class="text-xl font-semibold text-gray-900">Mensajes del bot</h1>
        <p class="mt-1 text-sm text-gray-500">
            Lo que tus clientes leen. Los cambios se aplican en la conversación siguiente,
            sin reiniciar nada.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('panel.configuracion.mensajes.guardar') }}" class="mt-6 space-y-6">
            @csrf
            @method('PUT')

            @php
                $campos = [
                    ['welcome_message', 'Bienvenida',
                     'El primer mensaje que recibe alguien que te escribe por primera vez.'],
                    ['no_availability_message', 'Sin disponibilidad',
                     'Cuando piden turno y no hay ninguno libre. Es distinto de un error: la agenda simplemente está llena.'],
                    ['fallback_message', 'Algo falló',
                     'Cuando el calendario o WhatsApp no responden. Es el único que el cliente lee cuando algo se rompió de nuestro lado.'],
                ];
            @endphp

            @foreach ($campos as [$campo, $titulo, $ayuda])
                <div>
                    <label for="{{ $campo }}" class="block text-sm font-medium text-gray-700">{{ $titulo }}</label>
                    <p class="text-xs text-gray-500">{{ $ayuda }}</p>
                    <textarea id="{{ $campo }}" name="{{ $campo }}" rows="3" maxlength="1024"
                              class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >{{ old($campo, $config->{$campo}) }}</textarea>
                    {{-- Dejarlo vacío no manda un mensaje en blanco: vuelve al de fábrica. --}}
                    <p class="mt-1 text-xs text-gray-400">
                        Si lo dejás vacío se usa el de fábrica: «{{ \Illuminate\Support\Str::limit($porDefecto[$campo], 70) }}»
                    </p>
                </div>
            @endforeach

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
