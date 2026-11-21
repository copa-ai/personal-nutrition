@use('App\Filament\Pages\CoderPagina', 'P')
@php
    $estadoTexto = ['none' => 'Sin Coder', 'launching' => 'Lanzando…', 'running' => 'En marcha', 'error' => 'Error'][$estado['estado']] ?? 'Desconocido';
    $resultadoTexto = [
        'ninguno' => 'Aún no ha habido ninguna recarga', 'ok' => 'Recarga correcta', 'error_sintaxis' => 'No se recargó: error de sintaxis PHP',
        'error_arranque' => 'No se recargó: la aplicación no arranca con el código nuevo', 'error_salud' => 'La recarga falló al comprobar la web',
        'revertido' => 'Se volvió al último código bueno', 'restaurado_original' => 'Se restauró el código original',
    ][$recarga['resultado']] ?? '';
@endphp
<x-filament-panels::page>
    <div @if ($estado['estado'] === 'launching') wire:poll.5s @endif class="space-y-6">
        @if ($estado['ajeno'])
            <x-filament::section heading="Coder de otro dispositivo">
                <p>Otro dispositivo que comparte esta base de datos tiene el Coder. Esta página no lo gestiona desde aquí.</p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Estado">
            <ul class="list-disc pl-5 space-y-1">
                <li>Estado: <strong>{{ $estadoTexto }}</strong>
                    @if ($estado['estado'] === 'launching') (el nodo puede tardar unos minutos) @endif</li>
                @if ($estado['servicio'])
                    <li>Servicio: <code style="word-break: break-all">{{ $estado['servicio'] }}</code></li>
                @endif
                @if ($estado['error'])
                    <li>Último error: <strong>{{ $estado['error'] }}</strong></li>
                @endif
                @if ($estado['estado'] === 'running')
                    <li>Conexión SSH reenviada: <strong>{{ $estado['destino'] ? 'sí' : 'no' }}</strong></li>
                @endif
            </ul>
            @if ($estado['estado'] !== 'none')
                <div class="mt-4 flex flex-wrap gap-2">
                    {{ $this->pararAction }}
                    {{ $this->pararForzadoAction }}
                </div>
            @endif
        </x-filament::section>

        @if ($estado['estado'] === 'none' || ($estado['estado'] === 'error' && $estado['lease'] === null))
            <x-filament::section heading="Lanzar un Coder">
                <p class="mb-3">Un Coder es otro servicio Celaut que cambia el código de esta aplicación mientras funciona. Tiene que declarar una carpeta compartida de invitado con la etiqueta <code>{{ $tag }}</code>. Escribe su hash (64 caracteres hexadecimales) o sube un fichero <code>.celaut.bee</code> (hasta {{ $maximoMb }} MB).</p>
                <div class="space-y-3">
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="referencia" placeholder="hash del servicio (sha3-256, hex)" autocomplete="off" />
                    </x-filament::input.wrapper>
                    <div>
                        <input type="file" wire:model="paquete" accept=".bee" />
                        <div wire:loading wire:target="paquete">Subiendo el fichero…</div>
                        @error('paquete') <p class="text-danger-600">{{ $message }}</p> @enderror
                        @if ($rutaPaquete)
                            <p>Fichero guardado: <strong>{{ basename($rutaPaquete) }}</strong>
                                <x-filament::link tag="button" wire:click="quitarPaquete">quitar</x-filament::link></p>
                        @endif
                    </div>
                    <x-filament::button wire:click="preparar" wire:loading.attr="disabled" wire:target="preparar">Ver coste y recursos</x-filament::button>
                </div>

                @if ($preparacion)
                    <div class="mt-6 space-y-2">
                        <h3 class="font-semibold">Revisa antes de lanzar</h3>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>Servicio: <code style="word-break: break-all">{{ $preparacion['id'] }}</code></li>
                            @if ($preparacion['coste'])
                                <li>Coste del arranque: <strong>{{ P::mu($preparacion['coste']['cost_mu'] ?? null) }} MU</strong></li>
                                <li>Un periodo de mantenimiento: <strong>{{ P::mu($preparacion['coste']['init_maintenance_cost_mu'] ?? null) }} MU</strong>
                                    con los recursos de inicio y <strong>{{ P::mu($preparacion['coste']['max_maintenance_cost_mu'] ?? null) }} MU</strong> con los máximos</li>
                            @else
                                <li>Coste: <strong>desconocido</strong>@if ($preparacion['aviso_coste']) ({{ $preparacion['aviso_coste'] }}) @endif.
                                    Pon un máximo para el arranque más abajo.</li>
                            @endif
                            <li>Recursos: el nodo no los dice antes del arranque. Esta aplicación reserva hasta
                                {{ $recursos['memoria_gib'] }} GiB de memoria, {{ $recursos['disco_gib'] }} GiB de disco y {{ $recursos['cpus'] }} CPU para el Coder.</li>
                            <li>Carpeta compartida «{{ $tag }}»:
                                @if ($preparacion['spec_leida'])
                                    <strong>{{ $preparacion['spec_declara_share'] ? 'declarada' : 'NO declarada' }}</strong>
                                @else
                                    <strong>no se puede comprobar</strong> (el nodo no deja leer la especificación)
                                @endif</li>
                        </ul>
                        @if ($preparacion['bloqueo'])
                            <p class="text-danger-600"><strong>{{ $preparacion['bloqueo'] }}</strong></p>
                        @else
                            @if ($this->necesitaConfirmarSinSpec())
                                <label class="flex items-center gap-2">
                                    <x-filament::input.checkbox wire:model="confirmaSinSpec" />
                                    <span>Confirmo que este servicio monta la carpeta compartida «{{ $tag }}».</span>
                                </label>
                            @endif
                            <div class="max-w-md">
                                <label class="block text-sm">Máximo que puede costar el arranque, en MU (opcional)</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" inputmode="numeric" wire:model="inicialMu" placeholder="sin máximo propio" />
                                </x-filament::input.wrapper>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                {{ $this->confirmarLanzamientoAction }}
                                <x-filament::button color="gray" wire:click="cancelarPreparacion">Cancelar</x-filament::button>
                            </div>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Saldo">
            @if ($saldo)
                <ul class="list-disc pl-5 space-y-1">
                    <li>Saldo del Coder: <strong>{{ P::mu($saldo['saldo_coder_mu']) }} MU</strong>
                        (quedan unas <strong>{{ P::horas($saldo['horas_coder']) }}</strong>)</li>
                    <li>Saldo de este servicio: <strong>{{ P::mu($saldo['saldo_nutrition_mu']) }} MU</strong>
                        (quedan unas <strong>{{ P::horas($saldo['horas_nutrition']) }}</strong>)</li>
                    <li>Enviado al Coder en esta vida: <strong>{{ P::mu($saldo['enviado_mu']) }} MU</strong></li>
                    <li>Se puede enviar ahora como mucho: <strong>{{ P::mu($saldo['maximo_mu']) }} MU</strong></li>
                    @if ($saldo['ultimo_rechazo'])
                        <li>Último rechazo ({{ $saldo['ultimo_rechazo']['control'] ?? '?' }}): <strong>{{ $saldo['ultimo_rechazo']['motivo'] ?? '' }}</strong></li>
                    @endif
                </ul>
            @else
                <p>No se pudo leer el saldo.</p>
            @endif
            <p class="mt-2 text-sm">Las horas son una estimación. Las del servicio salen de las lecturas de saldo de los últimos minutos; sin ellas no se envía nada.</p>
            @if ($estado['estado'] === 'running')
                <div class="mt-3">{{ $this->anadirSaldoAction }}</div>
            @endif

            <h3 class="mt-6 font-semibold">Límites de saldo</h3>
            <div class="grid gap-3 mt-2 max-w-xl">
                <div>
                    <label class="block text-sm">Tope total de MU para este Coder (vacío: el de por defecto, 24 h de mantenimiento con margen
                        @if ($saldo && $saldo['tope_por_defecto']) ; ahora {{ P::mu($saldo['tope_mu']) }} @endif)</label>
                    <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" wire:model="tope" /></x-filament::input.wrapper>
                </div>
                <div>
                    <label class="block text-sm">Porcentaje máximo del saldo conjunto que puede tener el Coder (1 a 100)</label>
                    <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" wire:model="porcentaje" /></x-filament::input.wrapper>
                </div>
                <div>
                    <label class="block text-sm">Vida mínima de este servicio tras un envío, en horas</label>
                    <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" wire:model="horasMinimas" /></x-filament::input.wrapper>
                </div>
                <div><x-filament::button wire:click="guardarLimites">Guardar límites</x-filament::button></div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Código en vivo">
            <ul class="list-disc pl-5 space-y-1">
                <li>Contra el original:
                    @if ($recarga['igual_original'] === true) <strong>igual</strong>
                    @elseif ($recarga['cambios'] !== null) <strong>{{ $recarga['cambios'] }} fichero(s) distintos</strong>
                    @else <strong>desconocido</strong> @endif
                    @if ($recarga['arbol_vivo']) (huella <code>{{ substr($recarga['arbol_vivo'], 0, 12) }}</code>) @endif</li>
                <li>Vigilante: <strong>{{ $recarga['disponible'] ? $recarga['estado'] : 'sin datos (no ha escrito su estado)' }}</strong>
                    @if ($recarga['pendiente']) · hay cambios sin cargar @endif
                    @if ($recargaPedida) · recarga pedida @endif
                    @if ($restauracionPedida) · restauración pedida @endif</li>
                <li>Última recarga: <strong>{{ $resultadoTexto }}</strong>
                    @if ($recarga['fecha']) ({{ $recarga['fecha'] }}) @endif
                    @if ($recarga['mensaje']) <br><code style="word-break: break-all">{{ $recarga['mensaje'] }}</code> @endif</li>
                @if ($recarga['ultima_buena'])
                    <li>Última recarga buena: {{ $recarga['ultima_buena'] }} ({{ $recarga['recargas'] }} desde el arranque)</li>
                @endif
            </ul>
            <label class="mt-4 flex items-center gap-2">
                <x-filament::input.checkbox wire:model.live="autoRecarga" />
                <span>Recarga automática. Apagada, los cambios se ignoran hasta pulsar «Recargar ahora».</span>
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
                {{ $this->recargarAhoraAction }}
                {{ $this->restaurarOriginalAction }}
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Claves SSH">
        <p class="mb-2">Una clave pública por línea (ssh-ed25519, ecdsa-sha2-nistp256/384/521 o ssh-rsa; hasta {{ $maximoClaves }}). Sin opciones delante de la clave. El Coder lee los cambios sin reiniciarse.</p>
        <textarea wire:model="claves" rows="6" spellcheck="false" style="width: 100%; font-family: monospace; font-size: 0.85rem" class="rounded-lg"></textarea>
        @if ($erroresClaves)
            <ul class="list-disc pl-5 text-danger-600 mt-2">
                @foreach ($erroresClaves as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        @endif
        <div class="mt-3"><x-filament::button wire:click="guardarClaves">Guardar claves</x-filament::button></div>
    </x-filament::section>

    <x-filament::section heading="Cómo conectar">
        <ol class="list-decimal pl-5 space-y-1">
            <li>Abre el túnel en tu ordenador (sustituye el nombre por el id de esta instancia):
                <pre><code>{{ $comandoTunel }}</code></pre></li>
            <li>Conéctate con SSH (usuario <code>{{ $usuario }}</code>, solo con clave):
                <pre><code>{{ $comandoSsh }}</code></pre></li>
            <li>O añade esto a <code>~/.ssh/config</code>:
                <pre><code>{{ $configSsh }}</code></pre></li>
            <li>VS Code: con el túnel abierto, usa «Remote-SSH: Connect to Host…» y elige <code>nutrition-coder</code>. Remote-SSH abre varias conexiones: cada una del túnel tiene un coste en MU para esta instancia.</li>
        </ol>
        <p class="mt-2 text-sm">El Coder guarda su trabajo en git dentro de su servicio: la carpeta compartida se pierde cuando este servicio se para.</p>
    </x-filament::section>
</x-filament-panels::page>
