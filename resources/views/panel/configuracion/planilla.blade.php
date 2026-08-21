<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planilla de leads · AgendaLlena</title>
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
        <h1 class="text-xl font-semibold text-gray-900">Planilla de leads</h1>
        <p class="mt-1 text-sm text-gray-500">
            Dónde caen las consultas que llegan por WhatsApp. Es tu planilla, en tu Drive:
            nosotros solo escribimos en ella.
        </p>

        @if (session('exito'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('exito') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        {{--
            AC-26.1 · Confirmado significa que la dueña lo ve, no que lo sepa la
            base: es lo único que le permite darse cuenta de que pegó la URL
            equivocada.
        --}}
        <div class="mt-6 rounded-md border border-gray-200 bg-white p-4">
            <h2 class="text-sm font-medium text-gray-700">Planilla vinculada</h2>

            @if ($vinculo)
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex gap-2">
                        <dt class="text-gray-500">Planilla:</dt>
                        <dd class="break-all font-mono text-gray-900">{{ $vinculo->spreadsheet_id }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-gray-500">Hoja:</dt>
                        <dd class="text-gray-900">{{ $vinculo->sheet_name }}</dd>
                    </div>
                </dl>
                <a class="mt-2 inline-block text-sm text-gray-500 underline hover:text-gray-900"
                   href="https://docs.google.com/spreadsheets/d/{{ $vinculo->spreadsheet_id }}/edit"
                   target="_blank" rel="noopener">Abrirla en Google Sheets</a>
            @else
                <p class="mt-2 text-sm text-gray-500">
                    Todavía no vinculaste ninguna. Los leads no se están escribiendo en ningún lado.
                </p>
            @endif
        </div>

        <form method="POST" action="{{ route('panel.configuracion.planilla.guardar') }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="url" class="block text-sm font-medium text-gray-700">URL de tu planilla</label>
                <p class="text-xs text-gray-500">
                    Abrila en Google Sheets y copiá la dirección del navegador.
                    Tiene que estar compartida como editor con la cuenta de Google que conectaste.
                </p>
                <input id="url" name="url" type="text" maxlength="2048"
                       placeholder="https://docs.google.com/spreadsheets/d/…/edit"
                       value="{{ old('url') }}"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="hoja" class="block text-sm font-medium text-gray-700">Hoja</label>
                {{-- Elegir la hoja no es un lujo: escribir en la primera que haya
                     le pisa la que ya estaba usando para otra cosa. --}}
                <p class="text-xs text-gray-500">El nombre de la solapa, tal cual figura abajo en Sheets.</p>
                <input id="hoja" name="hoja" type="text" maxlength="191"
                       value="{{ old('hoja', $vinculo->sheet_name ?? 'Leads') }}"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>

            <button type="submit"
                    class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">
                Vincular
            </button>
        </form>

        {{-- AC-26.2 · Sin esto, la única salida sería "andá a Drive y creala vos", y
             la vinculación se abandona en el paso dos. --}}
        <div class="mt-8 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-medium text-gray-700">¿No tenés una planilla?</h2>
            <p class="mt-1 text-xs text-gray-500">
                Te creamos una en tu Drive con las columnas ya puestas:
                {{ implode(' · ', $encabezados) }}.
            </p>

            <form method="POST" action="{{ route('panel.configuracion.planilla.crear') }}" class="mt-3 flex gap-2">
                @csrf
                <input name="nombre" type="text" maxlength="191"
                       value="{{ old('nombre', 'Leads · '.$tenant->name) }}"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <button type="submit"
                        class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                    Crearla
                </button>
            </form>
        </div>

        <a href="{{ route('panel') }}" class="mt-8 inline-block text-sm text-gray-500 hover:text-gray-900">
            Volver al panel
        </a>
    </div>
</body>
</html>
