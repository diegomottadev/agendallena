<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · AgendaLlena</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 antialiased">
    <div class="mx-auto flex min-h-screen max-w-sm items-center px-6">
        <div class="w-full">
            <h1 class="text-xl font-semibold text-gray-900">AgendaLlena</h1>
            <p class="mt-1 text-sm text-gray-500">Ingresá para ver tu panel.</p>

            @if ($errors->any())
                <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.entrar') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Correo</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}"
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
                    <input id="password" name="password" type="password" required
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="recordarme" value="1" class="rounded border-gray-300">
                    Recordarme
                </label>

                <button type="submit"
                        class="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">
                    Ingresar
                </button>
            </form>
        </div>
    </div>
</body>
</html>
