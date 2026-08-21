<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel · AgendaLlena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-2xl items-center justify-between px-6 py-3">
            <div>
                <span class="text-sm font-semibold text-gray-900">{{ $tenant->name }}</span>
                <span class="ml-2 text-xs text-gray-500">
                    {{ $usuario->name }} · {{ $usuario->role->etiqueta() }}
                </span>
            </div>
            <div class="flex items-center gap-4">
                {{-- Pausar el bot es de los tres roles: sin @if. --}}
                <a href="{{ route('panel.conversaciones') }}"
                   class="text-sm text-gray-500 hover:text-gray-900">Conversaciones</a>
                @if ($usuario->puedeConfigurar())
                    <a href="{{ route('panel.configuracion.agenda') }}"
                       class="text-sm text-gray-500 hover:text-gray-900">Agenda</a>
                    <a href="{{ route('panel.configuracion.mensajes') }}"
                       class="text-sm text-gray-500 hover:text-gray-900">Mensajes del bot</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-gray-900">Salir</button>
                </form>
            </div>
        </div>
    </header>

    {{-- T-034 · AC-25.2 · El aviso de integración caída, en todas las pantallas. --}}
    @include('panel.aviso-integraciones')

    {{--
        Los datos van serializados en el atributo y no por una llamada a la API:
        exponer un endpoint que liste integraciones sería una superficie más que
        autorizar, para datos que ya viajan con esta página.

        `puede-configurar` es solo para la interfaz. **El permiso real lo aplica
        el middleware `rol:configurar` del lado del servidor**: ocultar un botón
        no es un permiso, es una sugerencia.
    --}}
    <div
        id="panel"
        data-integraciones="{{ json_encode($integraciones) }}"
        data-tenant="{{ $tenant->slug }}"
        data-puede-configurar="{{ $usuario->puedeConfigurar() ? '1' : '' }}"
    ></div>
</body>
</html>
