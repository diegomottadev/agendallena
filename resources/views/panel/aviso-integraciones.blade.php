{{--
    T-034 · AC-25.2 y AC-25.4 · El aviso de integración caída.

    Se incluye en **todas** las pantallas del panel: el dueño entra a marcar
    asistencia o a mirar una conversación, no a revisar integraciones. Si el
    aviso viviera solo en la pantalla de integraciones se enteraría cuando ya fue.

    `avisoIntegraciones` la comparte el middleware `CompartirAvisosDeIntegracion`,
    así que esta vista no la recibe de ningún controlador y no se puede olvidar.

    AC-25.3 · No hay botón de "descartar" a propósito: el aviso se apaga solo
    cuando T-013 vuelve a escribir `connected`. Uno que se apaga a mano queda
    prendido para siempre y se aprende a ignorar.
--}}
@if (! empty($avisoIntegraciones ?? []))
    <div class="border-b border-red-200 bg-red-50">
        <div class="mx-auto max-w-4xl px-6 py-4">
            @foreach ($avisoIntegraciones as $aviso)
                <div class="@if (! $loop->first) mt-3 border-t border-red-200 pt-3 @endif">
                    <p class="text-sm font-semibold text-red-900">{{ $aviso['que_se_rompio'] }}</p>
                    <p class="mt-1 text-sm text-red-800">{{ $aviso['consecuencia'] }}</p>
                    <p class="mt-1 text-xs text-red-700">{{ $aviso['paso_siguiente'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
@endif
