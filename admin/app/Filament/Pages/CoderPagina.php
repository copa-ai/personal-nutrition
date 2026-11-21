<?php

namespace App\Filament\Pages;

use App\Jobs\LanzarCoder;
use App\Models\Auditoria;
use App\Services\Celaut\NodoException;
use App\Services\Coder\Coder;
use App\Services\Coder\CoderException;
use App\Services\Coder\ControlRecarga;
use App\Services\Coder\Saldo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Panel page for the Coder: a Celaut service that nutrition starts as a child to change the shared code.
 * The page holds no rule. It calls App\Services\Coder\Coder (life), Saldo (money) and ControlRecarga (reload flags).
 * A client can change every public property of a Livewire page. Properties that carry a decision are #[Locked].
 * See docs/coder/ for the design and docs/coder/decisions.md (entries 58 and later) for this page.
 */
class CoderPagina extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.coder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Coder';

    protected static ?string $title = 'Coder';

    protected static ?string $slug = 'coder';

    protected static ?int $navigationSort = 12;

    /** What the user typed: a service hash. */
    public string $referencia = '';

    /** The uploaded .celaut.bee file. It is removed after it is stored. */
    public $paquete = null;

    /** The stored package of an upload (path inside the packages folder). It wins over the typed hash. */
    #[Locked]
    public ?string $rutaPaquete = null;

    /** The result of `Coder::preparar` that the user must confirm. Null: no launch is ready. */
    #[Locked]
    public ?array $preparacion = null;

    /** The user says that the service mounts the share, when the spec cannot be read and the setting allows it. */
    public bool $confirmaSinSpec = false;

    /** Optional bound of the start cost in MU (celaut acquire --initial-mu). */
    public string $inicialMu = '';

    public string $claves = '';

    /** @var list<string> */
    public array $erroresClaves = [];

    public bool $autoRecarga = true;

    public string $tope = '';

    public string $porcentaje = '';

    public string $horasMinimas = '';

    public function mount(): void
    {
        $this->cargarFormularios();
    }

    private function cargarFormularios(): void
    {
        $coder = app(Coder::class);
        $limites = app(Saldo::class)->limites();
        $this->claves = implode("\n", $coder->claves());
        $this->autoRecarga = app(ControlRecarga::class)->autoActivo();
        $this->tope = $limites['tope_por_defecto'] || $limites['tope_mu'] === null ? '' : (string) $limites['tope_mu'];
        $this->porcentaje = (string) $limites['porcentaje'];
        $this->horasMinimas = (string) $limites['horas_minimas'];
    }

    // --- Launch ---

    /** The user stores a file. Check the name and the size, keep it under a random name and forget the temporary upload. */
    public function updatedPaquete(): void
    {
        $archivo = $this->paquete;
        $this->paquete = null;
        $this->preparacion = null;
        if (! $archivo instanceof TemporaryUploadedFile) {
            return;
        }
        $this->protegido(function () use ($archivo): void {
            try {
                // The client name only decides the extension check. The stored name is random.
                $r = app(Coder::class)->guardarPaquete((string) $archivo->getRealPath(), $archivo->getClientOriginalName());
            } finally {
                $archivo->delete();
            }
            $this->rutaPaquete = $r['ruta'];
            $this->referencia = '';
            Notification::make()->title('Fichero guardado')->body('Identificador del servicio: '.$r['id'])->success()->send();
        });
    }

    public function quitarPaquete(): void
    {
        $this->rutaPaquete = null;
        $this->preparacion = null;
    }

    /** First step: read the cost and the resources, then show them for confirmation. Nothing starts here. */
    public function preparar(): void
    {
        $this->preparacion = null;
        $this->confirmaSinSpec = false;
        $this->protegido(function (): void {
            $prep = app(Coder::class)->preparar($this->rutaPaquete ?? $this->referencia);
            $prep['bloqueo'] = self::bloqueo($prep);
            $this->preparacion = $prep;
        });
    }

    public function cancelarPreparacion(): void
    {
        $this->preparacion = null;
        $this->confirmaSinSpec = false;
    }

    /**
     * Why a launch is refused before it starts, or null. The same rules are in Coder::lanzar, which has the last word.
     *
     * @param  array<string, mixed>  $prep
     */
    public static function bloqueo(array $prep): ?string
    {
        $tag = (string) config('coder.tag_compartido');
        if (($prep['spec_leida'] ?? false) === false) {
            return config('coder.spec_desconocida') === 'confirmar'
                ? null
                : "No se puede leer la especificación del servicio, así que no se puede comprobar que monta la carpeta compartida «{$tag}». No se lanza.";
        }
        if (($prep['spec_declara_share'] ?? false) !== true) {
            return "El servicio no declara una carpeta compartida de invitado con la etiqueta «{$tag}»: no vería el código. No se lanza.";
        }

        return null;
    }

    /** True when the user must tick the box because the spec cannot be read. */
    public function necesitaConfirmarSinSpec(): bool
    {
        return is_array($this->preparacion) && ($this->preparacion['spec_leida'] ?? true) === false && config('coder.spec_desconocida') === 'confirmar';
    }

    public function confirmarLanzamientoAction(): Action
    {
        return Action::make('confirmarLanzamiento')->label('Lanzar el Coder')->icon(Heroicon::OutlinedRocketLaunch)
            ->visible(fn (): bool => is_array($this->preparacion) && ($this->preparacion['bloqueo'] ?? null) === null)
            ->requiresConfirmation()
            ->modalHeading('Lanzar el Coder')
            ->modalDescription(fn (): string => $this->descripcionLanzamiento())
            ->modalSubmitActionLabel('Sí, lanzar')
            ->action(fn () => $this->lanzar());
    }

    /** Warning for the user: the Coder runs with the rights of the app. See docs/coder/README.md, "Trust". */
    public const AVISO_CONFIANZA = 'ATENCIÓN: el Coder cambia el código que corre con los permisos de la aplicación. Puede leer sus claves y secretos y gastar su saldo. Los límites de saldo solo frenan un uso honesto. Lanza solo servicios en los que confíes del todo.';

    private function descripcionLanzamiento(): string
    {
        $p = $this->preparacion ?? [];
        $coste = $p['coste'] ?? null;
        $texto = $coste === null
            ? 'El coste del arranque no se conoce. '
            : 'El arranque cuesta unos '.self::mu($coste['cost_mu'] ?? null).' MU, y cada periodo de mantenimiento hasta '.self::mu($coste['max_maintenance_cost_mu'] ?? null).' MU. ';

        return $texto.'Este servicio pagará el saldo del Coder. Los controles de saldo limitan lo que se envía después. '
            .'Sin un máximo de arranque, el nodo fija el del arranque (una hora de mantenimiento aproximada) y esa primera carga no pasa por los controles. '
            .self::AVISO_CONFIANZA;
    }

    /** Second step: the user confirmed. Check again, then start the job. The job does the long work. */
    public function lanzar(): void
    {
        $this->protegido(function (): void {
            $coder = app(Coder::class);
            $prep = $coder->preparar($this->rutaPaquete ?? $this->referencia);
            $bloqueo = self::bloqueo($prep);
            if ($bloqueo !== null) {
                throw new CoderException($bloqueo);
            }
            $confirma = $this->necesitaConfirmarSinSpec();
            if ($confirma && ! $this->confirmaSinSpec) {
                throw new CoderException('Marca la casilla que confirma que el servicio monta la carpeta compartida.');
            }
            $inicial = trim($this->inicialMu);
            if ($inicial !== '') {
                $mu = Saldo::aMu($inicial, false);
                if ($mu === null || $mu <= 0) {
                    throw new CoderException('El máximo del arranque tiene que ser un número entero de MU mayor que 0, o estar vacío.');
                }
                app(Saldo::class)->comprobarInicial($mu);
                $inicial = (string) $mu;
            }

            LanzarCoder::dispatch($prep['referencia'], $confirma, $inicial === '' ? null : $inicial);
            $this->preparacion = null;
            $this->confirmaSinSpec = false;
            Notification::make()->title('Lanzamiento en marcha')->body('Puede tardar unos minutos. Esta página se actualiza sola.')->success()->send();
        });
    }

    // --- Stop, balance, reload, restore ---

    public function pararAction(): Action
    {
        return Action::make('parar')->label('Parar el Coder')->icon(Heroicon::OutlinedStopCircle)->color('danger')
            ->visible(fn (): bool => app(Coder::class)->estado()['estado'] !== Coder::NINGUNO)
            ->requiresConfirmation()
            ->modalHeading('Parar el Coder')
            ->modalDescription('Se cierran las conexiones SSH y se para el servicio. El saldo que le queda vuelve a este servicio. Lo que no esté guardado en git del Coder se pierde.')
            ->modalSubmitActionLabel('Sí, parar')
            ->action(function (): void {
                $this->protegido(function (): void {
                    $estado = app(Coder::class)->estado()['estado'];
                    $r = app(Coder::class)->parar($estado === Coder::LANZANDO);
                    Notification::make()->title('Coder parado')
                        ->body($r['refund_mu'] === null ? null : 'Reembolso: '.self::mu($r['refund_mu']).' MU.')->success()->send();
                });
            });
    }

    /** Stop for a Coder in the state "error" whose stop failed: clear the state even if the node refuses. */
    public function pararForzadoAction(): Action
    {
        return Action::make('pararForzado')->label('Forzar la parada')->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
            ->visible(fn (): bool => in_array(app(Coder::class)->estado()['estado'], [Coder::ERROR, Coder::LANZANDO], true))   // a launch can hang: the stop asks for this button
            ->requiresConfirmation()
            ->modalHeading('Forzar la parada')
            ->modalDescription('Se borra el estado del Coder aunque el nodo no confirme la parada. La instancia puede seguir en el nodo y gastar saldo hasta que se agote.')
            ->modalSubmitActionLabel('Sí, forzar')
            ->action(function (): void {
                $this->protegido(function (): void {
                    app(Coder::class)->parar(true);
                    Notification::make()->title('Estado del Coder borrado')->warning()->send();
                });
            });
    }

    public function anadirSaldoAction(): Action
    {
        return Action::make('anadirSaldo')->label('Añadir saldo')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (): bool => app(Coder::class)->estado()['estado'] === Coder::EN_MARCHA)
            ->schema([
                TextInput::make('monto')->label('Cantidad (MU)')->required()->maxLength(18)
                    ->helperText('Número entero. Se aplican el tope total, el porcentaje y la vida mínima de este servicio.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Añadir saldo al Coder')
            ->modalDescription('Las MU salen del saldo de este servicio y pasan al Coder.')
            ->modalSubmitActionLabel('Enviar')
            ->action(function (array $data): void {
                $this->protegido(function () use ($data): void {
                    $r = app(Saldo::class)->addBalance($data['monto'] ?? null);
                    if ($r['enviado']) {
                        Notification::make()->title('Saldo enviado')->body(self::mu($r['mu']).' MU para el Coder.')->success()->send();
                    } else {
                        Notification::make()->title('No se ha enviado el saldo')->body($r['motivo'])->danger()->send();
                    }
                });
            });
    }

    public function recargarAhoraAction(): Action
    {
        return Action::make('recargarAhora')->label('Recargar ahora')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->action(function (): void {
                $this->protegido(function (): void {
                    app(ControlRecarga::class)->pedirRecarga();
                    Notification::make()->title('Recarga pedida')->body('El vigilante la hace en unos segundos y revisa el código antes.')->success()->send();
                });
            });
    }

    public function restaurarOriginalAction(): Action
    {
        return Action::make('restaurarOriginal')->label('Restaurar el original')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Restaurar el código original')
            ->modalDescription('Se copia sobre la carpeta compartida el código con el que se empaquetó este servicio, y se recarga. Se pierden todos los cambios del Coder en esa carpeta.')
            ->modalSubmitActionLabel('Sí, restaurar')
            ->action(function (): void {
                $this->protegido(function (): void {
                    app(ControlRecarga::class)->pedirRestaurar();
                    Notification::make()->title('Restauración pedida')->body('El vigilante la hace en unos segundos.')->warning()->send();
                });
            });
    }

    public function updatedAutoRecarga(): void
    {
        $this->protegido(function (): void {
            $activo = (bool) $this->autoRecarga;
            app(ControlRecarga::class)->fijarAuto($activo);
            Auditoria::registrar('coder.recarga_auto', $activo ? 'Recarga automática activada' : 'Recarga automática desactivada', ['activa' => $activo]);
        });
        $this->autoRecarga = app(ControlRecarga::class)->autoActivo();
    }

    // --- Limits and keys ---

    public function guardarLimites(): void
    {
        $this->protegido(function (): void {
            $l = app(Saldo::class)->guardarLimites($this->tope, $this->porcentaje, $this->horasMinimas);
            $this->tope = $l['tope_por_defecto'] || $l['tope_mu'] === null ? '' : (string) $l['tope_mu'];
            $this->porcentaje = (string) $l['porcentaje'];
            $this->horasMinimas = (string) $l['horas_minimas'];
            Notification::make()->title('Límites guardados')->success()->send();
        });
    }

    public function guardarClaves(): void
    {
        $this->erroresClaves = [];
        $this->protegido(function (): void {
            $r = app(Coder::class)->guardarClaves($this->claves);
            if ($r['errores'] !== []) {
                $this->erroresClaves = $r['errores'];
                Notification::make()->title('Las claves no se han guardado')->body(implode("\n", $r['errores']))->danger()->send();

                return;
            }
            $this->claves = implode("\n", $r['claves']);
            Notification::make()->title('Claves guardadas')->body(count($r['claves']).' clave(s). El Coder las lee sin reiniciarse.')->success()->send();
        });
    }

    // --- View ---

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $coder = app(Coder::class);
        $saldo = app(Saldo::class);
        $control = app(ControlRecarga::class);
        $estado = $this->seguro(fn (): array => $coder->estado(), ['estado' => Coder::NINGUNO, 'servicio' => null, 'lease' => null, 'uri' => null, 'error' => null, 'lanzado_en' => null, 'ajeno' => false, 'claves' => 0, 'destino' => false, 'horas_vida' => null, 'saldo_mu' => null, 'enviado_mu' => null]);

        $puerto = (int) config('coder.puerto_ssh');
        $usuario = (string) config('coder.usuario_ssh');

        return [
            'estado' => $estado,
            'saldo' => $this->seguro(fn (): ?array => $saldo->status(), null),
            'recarga' => $control->estado(),
            'recargaPedida' => $control->recargaPedida(),
            'restauracionPedida' => $control->restauracionPedida(),
            'recursos' => config('coder.recursos'),
            'maximoMb' => (int) config('coder.paquete.tamano_maximo_mb'),
            'tag' => (string) config('coder.tag_compartido'),
            'usuario' => $usuario,
            'puerto' => $puerto,
            'comandoTunel' => "nodo tunnel <instancia-de-esta-app> {$puerto} --listen {$puerto}",
            'configSsh' => "Host nutrition-coder\n    HostName 127.0.0.1\n    Port {$puerto}\n    User {$usuario}\n    IdentityFile ~/.ssh/id_ed25519\n    HostKeyAlias nutrition-coder",
            'comandoSsh' => "ssh -p {$puerto} -o HostKeyAlias=nutrition-coder {$usuario}@127.0.0.1",
            'maximoClaves' => (int) config('coder.claves.maximo'),
        ];
    }

    /** Format a MU amount (integer or digit string) for the screen. */
    public static function mu(mixed $valor): string
    {
        return is_int($valor) || (is_string($valor) && preg_match('/^\d{1,18}$/', $valor) === 1) ? number_format((int) $valor, 0, ',', '.') : '?';
    }

    /** Hours for the screen, or a dash when unknown. */
    public static function horas(mixed $valor): string
    {
        return is_int($valor) || is_float($valor) ? number_format((float) $valor, 1, ',', '.').' h' : 'desconocidas';
    }

    /** Run a user action. Show an error as a notification. The page never crashes. */
    private function protegido(callable $fn): void
    {
        try {
            $fn();
        } catch (CoderException|NodoException $e) {
            Notification::make()->title('No se pudo completar la acción')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Error inesperado')->body('Mira el registro de la aplicación para ver los detalles.')->danger()->send();
        }
    }

    /**
     * Read a value for the view. If it fails, use the fallback.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  T  $defecto
     * @return T
     */
    private function seguro(callable $fn, mixed $defecto): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $defecto;
        }
    }
}
