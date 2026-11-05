<x-filament-panels::page>
    <x-filament::section heading="Qué es esto">
        Varios dispositivos pueden usar el sistema compartiendo la misma base de datos. Para que un dispositivo nuevo se una,
        hay que arrancarlo con estos mismos valores. Cópialos de aquí; los secretos no los compartas con nadie más.
    </x-filament::section>

    <x-filament::section heading="Variables">
        <div class="space-y-4" x-data="{ copiado: null }">
            @foreach ($variables as $v)
                <div x-data="{ ver: {{ $v['secreto'] ? 'false' : 'true' }} }">
                    <div class="flex items-baseline justify-between gap-2">
                        <code class="font-semibold">{{ $v['nombre'] }}</code>
                        <span class="text-xs text-gray-500">{{ $v['ayuda'] }}</span>
                    </div>
                    @if (filled($v['valor']))
                        <div class="mt-1 flex items-center gap-2">
                            <input type="text" readonly class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 font-mono text-sm dark:border-white/10 dark:bg-white/5"
                                   x-bind:type="ver ? 'text' : 'password'" value="{{ $v['valor'] }}">
                            @if ($v['secreto'])
                                <x-filament::button color="gray" size="sm" x-on:click="ver = ! ver">Ver</x-filament::button>
                            @endif
                            <x-filament::button size="sm"
                                x-on:click="navigator.clipboard.writeText(@js($v['valor'])); copiado = @js($v['nombre']); setTimeout(() => copiado = null, 2000)">
                                <span x-text="copiado === @js($v['nombre']) ? 'Copiado' : 'Copiar'"></span>
                            </x-filament::button>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-gray-500">Sin valor en este dispositivo.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Código QR de NUTRITION_RED">
        @if (filled($red))
            <div class="space-y-3"
                 data-qr-script="{{ asset('vendor/qrcode.min.js') }}"
                 x-data="{
                     async dibujar() {
                         if (! window.qrcode) {
                             window.qrcodeCargando = window.qrcodeCargando || new Promise((resolver, rechazar) => {
                                 const script = document.createElement('script');
                                 script.src = this.$el.dataset.qrScript;
                                 script.onload = resolver;
                                 script.onerror = () => { window.qrcodeCargando = null; rechazar(); };
                                 document.head.appendChild(script);
                             });
                             try { await window.qrcodeCargando; } catch (e) { return; }
                         }
                         const qr = window.qrcode(0, 'M');
                         qr.addData(@js($red));
                         qr.make();
                         this.$refs.qr.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
                     },
                 }"
                 x-init="dibujar()">
                <div wire:ignore class="mx-auto w-64 max-w-full rounded-lg bg-white p-3" data-qr-nutrition-red>
                    <div x-ref="qr"></div>
                </div>
                <p class="text-center text-sm text-gray-500">Léelo con la cámara del móvil para obtener la variable NUTRITION_RED.</p>
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    Atención: este código y la cadena NUTRITION_RED son una credencial completa (APP_KEY y contraseña de la base).
                    No lo muestres ni lo compartas con nadie que no deba tener acceso.
                </p>
            </div>
        @else
            <p class="text-sm text-gray-500">Sin valor en este dispositivo, no hay código QR.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Arrancar otro dispositivo">
        <div x-data="{ copiado: false }" class="space-y-2">
            <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ $orden }}</pre>
            <x-filament::button size="sm" x-on:click="navigator.clipboard.writeText(@js($orden)); copiado = true; setTimeout(() => copiado = false, 2000)">
                <span x-text="copiado ? 'Copiado' : 'Copiar orden'"></span>
            </x-filament::button>
            <p class="text-sm text-gray-500">
                Los nodos tienen que ser peers (<code>nodo connect</code>). El nodo solo ofrece la base de otro dispositivo si su dirección es
                pública; en una red local pon <code>service_networks.ALLOW_PRIVATE_PEER_ADDRESSES: true</code> en el <code>config.yaml</code> del
                nodo que pregunta.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
