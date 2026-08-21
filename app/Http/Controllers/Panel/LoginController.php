<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * T-046 · Autenticación del panel.
 */
class LoginController extends Controller
{
    public function mostrar()
    {
        return Auth::check() ? redirect()->route('panel') : view('login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /*
         * Límite por correo **y** por IP. Solo por correo, un atacante prueba
         * mil claves contra mil cuentas sin frenarse nunca; solo por IP, basta
         * rotar de red. Cinco intentos por minuto no molesta a nadie que
         * recuerde su clave.
         */
        $clave = 'login:'.strtolower($datos['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Probá de nuevo en '
                    .RateLimiter::availableIn($clave).' segundos.',
            ]);
        }

        if (! Auth::attempt($datos, $request->boolean('recordarme'))) {
            RateLimiter::hit($clave, 60);

            /*
             * Un solo mensaje para "no existe" y para "clave incorrecta": si se
             * distinguen, el formulario se vuelve un verificador de qué correos
             * tienen cuenta. Y en el log **no va la contraseña**, ni siquiera su
             * longitud.
             */
            Log::warning('Intento de login fallido', [
                'email' => $datos['email'],
                'ip' => $request->ip(),
                'codigo' => 'AUTH_LOGIN_FALLIDO',
            ]);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden.',
            ]);
        }

        RateLimiter::clear($clave);

        // Sesión nueva tras autenticar: sin esto, un identificador de sesión
        // fijado por un tercero antes del login sigue siendo válido después.
        $request->session()->regenerate();

        Log::info('Login exitoso', [
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
            'rol' => $request->user()->role?->value,
            'codigo' => 'AUTH_LOGIN_OK',
        ]);

        return redirect()->intended(route('panel'));
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
