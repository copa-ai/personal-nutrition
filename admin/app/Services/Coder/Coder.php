<?php

namespace App\Services\Coder;

use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\CoderEstado;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use App\Services\Instancia;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Start, watch and stop the Coder: a Celaut service that nutrition runs as a child. It changes the shared code (the project share).
 *
 * The state lives in App\Models\Ajuste (keys "coder.*"). The files for the other pieces are in config coder.directorio:
 * destino.json (the SSH forwarder reads it) and, in the share, .coder/authorized_keys (the Coder reads it).
 * The money rules (caps, percentage, minimum life) are in the class Saldo.
 *
 * A user error is a CoderException with a Spanish message. A node error is wrapped in a CoderException too.
 */
class Coder
{
    public const NINGUNO = 'none';

    public const LANZANDO = 'launching';

    public const EN_MARCHA = 'running';

    public const ERROR = 'error';

    /** Keys of DE_VIDA that live in the table coder_estado (no audit row). The others live in ajustes. */
    private const VOLATIL = ['enviado_mu', 'horas_vida', 'saldo_mu', 'precio', 'ultimo_rechazo'];

    /** Settings that belong to one life of the Coder. The stop action sets them to null. */
    private const DE_VIDA = ['servicio', 'tipo', 'referencia', 'lease', 'uri', 'error', 'lanzado_en', 'enviado_mu', 'horas_vida', 'saldo_mu', 'precio', 'ultimo_rechazo'];

    public function __construct(
        private readonly Nodo $nodo,
        private readonly LectorEspecificacion $lector,
        private readonly SondaSsh $sonda,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(Nodo::desdeConfig(), new LectorEspecificacion, new SondaSsh);
    }

    // --- Reference (hash or package) ---

    /**
     * Check what the user typed: a service id (64 hex characters) or the path of a stored .celaut.bee file.
     *
     * @return array{0: string, 1: string} "hash" and the id in lower case, or "paquete" and the real path
     */
    public function referencia(string $texto): array
    {
        $texto = trim($texto);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $texto) === 1) {
            return ['hash', strtolower($texto)];
        }
        if (preg_match('/^[0-9a-fA-F]+$/', $texto) === 1) {
            throw new CoderException('El hash del servicio tiene que tener 64 caracteres hexadecimales (tiene '.strlen($texto).').');
        }

        $carpeta = realpath($this->rutaPaquetes());
        $ruta = $texto === '' ? false : realpath($texto);
        $extension = (string) config('coder.paquete.extension');
        if ($carpeta !== false && $ruta !== false && is_file($ruta) && str_starts_with($ruta, $carpeta.'/') && str_ends_with($ruta, $extension)) {
            return ['paquete', $ruta];
        }

        throw new CoderException('Escribe el hash del servicio (64 caracteres hexadecimales) o sube un fichero '.$extension.'.');
    }

    /**
     * Store an uploaded .celaut.bee file and check that celaut reads it.
     *
     * @return array{ruta: string, id: string}
     */
    public function guardarPaquete(string $origen, string $nombreOriginal): array
    {
        $extension = (string) config('coder.paquete.extension');
        if (! str_ends_with(strtolower($nombreOriginal), $extension)) {
            throw new CoderException('El fichero tiene que acabar en '.$extension.'.');
        }
        $maximo = (int) config('coder.paquete.tamano_maximo_mb') * 1024 * 1024;
        $tamano = @filesize($origen);
        if ($tamano === false || $tamano === 0) {
            throw new CoderException('El fichero está vacío o no se puede leer.');
        }
        if ($tamano > $maximo) {
            throw new CoderException('El fichero pesa más de '.config('coder.paquete.tamano_maximo_mb').' MB.');
        }

        $carpeta = $this->rutaPaquetes();
        if (! is_dir($carpeta) && ! @mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
            throw new CoderException('No se puede crear la carpeta de paquetes.');
        }
        $ruta = $carpeta.'/'.bin2hex(random_bytes(8)).$extension;
        if (! @copy($origen, $ruta)) {
            throw new CoderException('No se pudo guardar el fichero.');
        }
        chmod($ruta, 0600);

        try {
            $id = $this->nodo->idDePaquete($ruta);
        } catch (NodoException $e) {
            @unlink($ruta);
            throw new CoderException('El fichero no es un paquete Celaut válido: '.$e->getMessage());
        }
        if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
            @unlink($ruta);
            throw new CoderException('El fichero no es un paquete Celaut válido.');
        }

        return ['ruta' => $ruta, 'id' => $id];
    }

    /**
     * What the panel shows before the user confirms a launch: the id, the cost, the resource bound and what we know of the spec.
     * The cost is null if the node cannot price the service (for example, the node does not hold it yet).
     *
     * @return array{tipo: string, referencia: string, id: string, coste: array<string, string>|null, aviso_coste: string|null, recursos: array<string, mixed>, spec_leida: bool, spec_declara_share: bool|null}
     */
    public function preparar(string $referencia): array
    {
        [$tipo, $ref] = $this->referencia($referencia);

        try {
            $id = $tipo === 'hash' ? $ref : $this->nodo->idDePaquete($ref);
        } catch (NodoException $e) {
            throw new CoderException('No se pudo leer el paquete: '.$e->getMessage());
        }

        $coste = null;
        $aviso = null;
        try {
            $coste = $tipo === 'hash' ? $this->nodo->costePorHash($id) : $this->nodo->costePorPaquete($ref);
        } catch (NodoException $e) {
            $aviso = $e->getMessage();
        }

        $tags = $this->lector->tagsInvitado($tipo, $ref);

        return [
            'tipo' => $tipo,
            'referencia' => $ref,
            'id' => $id,
            'coste' => $coste,
            'aviso_coste' => $aviso,
            // celaut cost prints no resources. The panel shows the admission bound of service.json.
            'recursos' => [
                'conocidos' => null,
                'limite' => config('coder.recursos'),
            ],
            'spec_leida' => $tags !== null,
            'spec_declara_share' => $tags === null ? null : in_array(config('coder.tag_compartido'), $tags, true),
        ];
    }

    /** Refuse a service that does not declare the guest share. If the spec cannot be read, refuse unless the setting allows a confirmation. */
    private function comprobarSpec(string $tipo, string $ref, bool $confirmaSinSpec): void
    {
        $tag = (string) config('coder.tag_compartido');
        $tags = $this->lector->tagsInvitado($tipo, $ref);

        if ($tags === null) {
            if (config('coder.spec_desconocida') === 'confirmar' && $confirmaSinSpec) {
                return;
            }
            throw new CoderException("No se puede leer la especificación del servicio, así que no se puede comprobar que monta la carpeta compartida «{$tag}». No se lanza.");
        }
        if (! in_array($tag, $tags, true)) {
            throw new CoderException("El servicio no declara una carpeta compartida de invitado con la etiqueta «{$tag}»: no vería el código. No se lanza.");
        }
    }

    // --- Launch and stop ---

    /**
     * Start the Coder. This can take minutes, so the panel calls it from a job.
     * Steps: checks, host key, authorized keys, `celaut acquire`, SSH banner test, destino.json.
     *
     * @param  string|null  $inicialMu  most MU that the start can cost. null: config coder.lanzamiento.inicial_mu
     * @return array<string, mixed> the state (see estado())
     */
    public function lanzar(string $referencia, bool $confirmaSinSpec = false, ?string $inicialMu = null): array
    {
        $actual = $this->estado();
        if ($actual['ajeno']) {
            throw new CoderException('Otro dispositivo que comparte esta base de datos ya tiene un Coder.');
        }
        if ($actual['estado'] !== self::NINGUNO && ! ($actual['estado'] === self::ERROR && $actual['lease'] === null)) {
            throw new CoderException('Ya hay un Coder. Páralo antes de lanzar otro.');
        }

        [$tipo, $ref] = $this->referencia($referencia);
        $this->comprobarSpec($tipo, $ref, $confirmaSinSpec);

        try {
            $id = $tipo === 'hash' ? $ref : $this->nodo->idDePaquete($ref);
            $claveHost = $this->claveHostBase64();
            // Write the keys before the start: the share can be new, and a failure here must cost nothing.
            $this->escribirClavesAutorizadas($this->claves());
        } catch (CoderException $e) {
            throw $e;
        } catch (RuntimeException $e) {   // includes NodoException
            throw new CoderException('No se pudo preparar el lanzamiento: '.$e->getMessage());
        }

        $this->quitarDestino();
        $this->limpiar();
        try {
            // Tell the reload watcher: a reload now would kill this launch (the queue workers restart).
            $this->escribirLanzando();
            Ajuste::fijar('coder.estado', self::LANZANDO);
            Ajuste::fijar('coder.instancia', Instancia::id());
            Ajuste::fijar('coder.servicio', $id);
            Ajuste::fijar('coder.tipo', $tipo);
            Ajuste::fijar('coder.referencia', $ref);
            Ajuste::fijar('coder.lanzado_en', CarbonImmutable::now()->toIso8601String());
            CoderEstado::fijar('coder.enviado_mu', '0');
            Auditoria::registrar('coder.lanzamiento', 'Lanzamiento del Coder', ['servicio' => $id, 'tipo' => $tipo]);

            $entorno = [(string) config('coder.variable_clave_host') => $claveHost];
            $inicialMu ??= config('coder.lanzamiento.inicial_mu') === null ? null : (string) config('coder.lanzamiento.inicial_mu');
            try {
                $r = $tipo === 'hash'
                    ? $this->nodo->adquirirPorHash($id, $entorno, $inicialMu)
                    : $this->nodo->adquirirPorPaquete($ref, $entorno, $inicialMu);
            } catch (NodoException $e) {
                throw $this->fallar('El nodo no lanzó el Coder: '.$e->getMessage(), null);
            }
            $lease = (int) $r['lease'];
            Ajuste::fijar('coder.lease', $lease);
            Ajuste::fijar('coder.uri', (string) $r['uri']);
            Saldo::olvidarMuestras();   // the start charge is not burn: the burn rate starts again

            [$host, $puerto] = $this->separarUri((string) $r['uri']);
            if ($host === null || ! $this->esperarSsh($host, $puerto)) {
                throw $this->fallar(
                    "El servicio lanzado no responde como SSH en {$r['uri']}. El primer puerto de su api tiene que ser el de SSH. Se ha parado.",
                    $lease,
                );
            }

            try {
                $this->escribirDestino($host, $puerto, $lease, $id);
            } catch (RuntimeException $e) {
                throw $this->fallar('No se pudo escribir el destino del reenviador: '.$e->getMessage(), $lease);
            }

            Ajuste::fijar('coder.estado', self::EN_MARCHA);
            Auditoria::registrar('coder.lanzado', 'Coder en marcha', ['servicio' => $id, 'lease' => $lease, 'uri' => $r['uri']]);

            return $this->estado();
        } finally {
            $this->quitarLanzando();
        }
    }

    /**
     * Stop the Coder: remove destino.json (this closes the SSH pipes), stop the lease, clear the state. It is safe to call it with no Coder.
     * If the node refuses the stop, the state becomes "error" and the lease stays, so the user can try again.
     * With $forzar, the state is cleared even if the node refuses. The lease can then stay in the pool.
     *
     * @return array{refund_mu: string|null}
     */
    public function parar(bool $forzar = false): array
    {
        $actual = $this->estado();
        if ($actual['ajeno']) {
            throw new CoderException('El Coder es de otro dispositivo que comparte esta base de datos.');
        }

        if ($actual['estado'] === self::LANZANDO && ! $forzar) {
            throw new CoderException('El Coder se está lanzando. Espera a que termine, o fuerza la parada si el lanzamiento se ha colgado.');
        }

        $this->quitarDestino();
        $this->quitarLanzando();
        $reembolso = null;
        if ($actual['lease'] !== null) {
            try {
                $reembolso = $this->nodo->parar($actual['lease'])['refund_mu'] ?? null;
            } catch (NodoException $e) {
                if (! $forzar) {
                    Ajuste::fijar('coder.estado', self::ERROR);
                    Ajuste::fijar('coder.error', 'No se pudo parar el Coder: '.$e->getMessage());
                    Auditoria::registrar('coder.parada_fallida', 'No se pudo parar el Coder', ['lease' => $actual['lease'], 'motivo' => $e->getMessage()]);

                    throw new CoderException('No se pudo parar el Coder: '.$e->getMessage());
                }
            }
        }

        $this->limpiar();
        Auditoria::registrar('coder.parado', 'Coder parado', ['lease' => $actual['lease'], 'reembolso_mu' => $reembolso, 'forzado' => $forzar]);

        return ['refund_mu' => is_string($reembolso) ? $reembolso : null];
    }

    /**
     * One pass of the watcher (nutrition:vigilar-coder). It checks that the lease is still in the pool.
     * If the Coder died, it removes destino.json and sets the state to "error". If no Coder exists, it removes a stale destino.json.
     * If the node does not answer, it changes nothing.
     *
     * @return string "ninguno", "ajeno", "sin_nodo", "vivo", "muerto" or "colgado"
     */
    public function vigilar(): string
    {
        $actual = $this->estado();
        if ($actual['ajeno']) {
            return 'ajeno';
        }
        if ($actual['estado'] === self::NINGUNO || $actual['estado'] === self::ERROR) {
            $this->quitarDestino();

            return 'ninguno';
        }

        if ($actual['estado'] === self::LANZANDO) {
            $desde = $actual['lanzado_en'] === null ? null : CarbonImmutable::parse($actual['lanzado_en']);
            if ($desde === null || $desde->addSeconds((int) config('coder.lanzamiento.colgado_s'))->isPast()) {
                $this->marcarMuerto('El lanzamiento del Coder no terminó (el proceso se interrumpió).', $actual['lease']);

                return 'colgado';
            }

            return 'vivo';
        }

        try {
            $enPool = array_column($this->nodo->instancias(), 'lease');
        } catch (NodoException) {
            return 'sin_nodo';
        }
        if (! in_array($actual['lease'], array_map('intval', $enPool), true)) {
            $this->marcarMuerto('El Coder ya no está en el nodo (se paró o se quedó sin saldo).', $actual['lease']);

            return 'muerto';
        }

        return 'vivo';
    }

    private function marcarMuerto(string $motivo, ?int $lease): void
    {
        $this->quitarDestino();
        $this->quitarLanzando();
        Ajuste::fijar('coder.estado', self::ERROR);
        Ajuste::fijar('coder.error', $motivo);
        Ajuste::fijar('coder.lease', null);
        Ajuste::fijar('coder.uri', null);
        Auditoria::registrar('coder.caido', 'El Coder ya no está', ['lease' => $lease, 'motivo' => $motivo]);
    }

    /** Record a failed launch. The lease, if it exists, is stopped. Return the exception for the caller to throw. */
    private function fallar(string $mensaje, ?int $lease): CoderException
    {
        $this->quitarDestino();
        if ($lease !== null) {
            try {
                $this->nodo->parar($lease);
            } catch (Throwable $e) {
                $mensaje .= ' No se pudo parar la instancia: '.$e->getMessage();
            }
        }
        Ajuste::fijar('coder.estado', self::ERROR);
        Ajuste::fijar('coder.error', $mensaje);
        Ajuste::fijar('coder.lease', null);
        Ajuste::fijar('coder.uri', null);
        Auditoria::registrar('coder.lanzamiento_fallido', 'Fallo al lanzar el Coder', ['lease' => $lease, 'motivo' => $mensaje]);

        return new CoderException($mensaje);
    }

    private function limpiar(): void
    {
        $this->quitarLanzando();
        Ajuste::fijar('coder.estado', self::NINGUNO);
        foreach (self::DE_VIDA as $clave) {
            if (in_array($clave, self::VOLATIL, true)) {
                CoderEstado::fijar('coder.'.$clave, null);
            } else {
                Ajuste::fijar('coder.'.$clave, null);
            }
        }
    }

    // --- State ---

    /**
     * The state for the panel.
     *
     * @return array{estado: string, servicio: string|null, lease: int|null, uri: string|null, error: string|null, lanzado_en: string|null, ajeno: bool, claves: int, destino: bool, horas_vida: float|int|null, saldo_mu: string|null, enviado_mu: string|null}
     */
    public function estado(): array
    {
        $estado = Ajuste::obtener('coder.estado', self::NINGUNO);
        $estado = in_array($estado, [self::LANZANDO, self::EN_MARCHA, self::ERROR], true) ? $estado : self::NINGUNO;
        $dueno = Ajuste::obtener('coder.instancia');
        $lease = Ajuste::obtener('coder.lease');

        return [
            'estado' => $estado,
            'servicio' => Ajuste::obtener('coder.servicio'),
            'lease' => $lease === null ? null : (int) $lease,
            'uri' => Ajuste::obtener('coder.uri'),
            'error' => Ajuste::obtener('coder.error'),
            'lanzado_en' => Ajuste::obtener('coder.lanzado_en'),
            // The table "ajustes" is in the shared database. Another device can own the Coder.
            'ajeno' => $estado !== self::NINGUNO && $dueno !== null && $dueno !== Instancia::id(),
            'claves' => count($this->claves()),
            'destino' => is_file($this->rutaDestino()),
            // Saldo writes these three settings. They are null until the first funding pass.
            'horas_vida' => CoderEstado::obtener('coder.horas_vida'),
            'saldo_mu' => CoderEstado::obtener('coder.saldo_mu'),
            'enviado_mu' => CoderEstado::obtener('coder.enviado_mu'),
        ];
    }

    // --- SSH keys of the users (authorized_keys in the share) ---

    /** @return list<string> */
    public function claves(): array
    {
        $claves = Ajuste::obtener('coder.claves_ssh', []);

        return is_array($claves) ? array_values(array_filter($claves, 'is_string')) : [];
    }

    /**
     * Check the text of the panel, write .coder/authorized_keys in the share and keep the list in the settings.
     * If one line is bad, nothing changes.
     *
     * @return array{claves: list<string>, errores: list<string>}
     */
    public function guardarClaves(string $texto): array
    {
        $r = ClavesSsh::validar($texto);
        if ($r['errores'] !== []) {
            return $r;
        }

        try {
            $this->escribirClavesAutorizadas($r['claves']);
        } catch (RuntimeException $e) {
            throw new CoderException('No se pudo escribir en la carpeta compartida: '.$e->getMessage());
        }
        $antes = $this->claves();
        Ajuste::fijar('coder.claves_ssh', $r['claves']);
        if ($antes !== $r['claves']) {
            Auditoria::registrar('coder.claves_cambiadas', 'Claves SSH del Coder cambiadas', ['antes' => count($antes), 'despues' => count($r['claves'])]);
        }

        return $r;
    }

    /** @param  list<string>  $claves */
    private function escribirClavesAutorizadas(array $claves): void
    {
        $carpeta = rtrim((string) config('coder.proyecto'), '/').'/.coder';
        if (! is_dir($carpeta) && ! @mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("no se puede crear {$carpeta}");
        }
        $this->escribirAtomico($carpeta.'/authorized_keys', $claves === [] ? '' : implode("\n", $claves)."\n", 0644);
    }

    // --- Host key ---

    /** The SSH host key as base64 of the private key file. It is made once and stays the same for all launches. */
    private function claveHostBase64(): string
    {
        $carpeta = (string) config('coder.secretos');
        $ruta = $carpeta.'/ssh_host_ed25519';
        $clave = is_file($ruta) ? (string) file_get_contents($ruta) : '';
        if (! str_starts_with($clave, '-----BEGIN OPENSSH PRIVATE KEY-----')) {
            if (! is_dir($carpeta) && ! @mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
                throw new RuntimeException("no se puede crear {$carpeta}");
            }
            $clave = ClaveSshHost::generar();
            $this->escribirAtomico($ruta, $clave, 0600);
            Auditoria::registrar('coder.clave_host_creada', 'Clave del servidor SSH del Coder creada');
        }

        return base64_encode($clave);
    }

    // --- Destination of the forwarder ---

    /** @return array{0: string|null, 1: int} host and port of "ip:port", or null for the host if the text is not valid */
    private function separarUri(string $uri): array
    {
        $puerto = (int) substr(strrchr($uri, ':') ?: ':0', 1);
        $host = trim(substr($uri, 0, (int) strrpos($uri, ':')), '[]');

        return $host === '' || $puerto < 1 || $puerto > 65535 ? [null, 0] : [$host, $puerto];
    }

    /** Wait until the address sends an SSH banner. The Coder needs some time to start its server. */
    private function esperarSsh(string $host, int $puerto): bool
    {
        $limite = time() + (int) config('coder.lanzamiento.espera_ssh_s');
        while (true) {
            $banner = $this->sonda->banner($host, $puerto, (int) config('coder.lanzamiento.conexion_ssh_s'));
            if ($banner !== null && str_starts_with($banner, 'SSH-2.0-')) {
                return true;
            }
            if (time() >= $limite) {
                return false;
            }
            sleep((int) config('coder.lanzamiento.pausa_ssh_s'));
        }
    }

    private function escribirDestino(string $host, int $puerto, int $lease, string $servicio): void
    {
        $this->escribirAtomico($this->rutaDestino(), json_encode([
            'host' => $host,
            'port' => $puerto,
            'lease' => $lease,
            'servicio' => $servicio,
            'escrito_en' => CarbonImmutable::now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES)."\n", 0644);
    }

    private function quitarDestino(): void
    {
        @unlink($this->rutaDestino());
    }

    /** Write the lock file for the reload watcher: the unix time in seconds. The watcher does not reload while it is young (coder.lanzamiento.colgado_s). */
    private function escribirLanzando(): void
    {
        try {
            $this->escribirAtomico($this->rutaLanzando(), time()."\n", 0644);
        } catch (RuntimeException) {
            // Without the lock a reload can kill the launch. The launch itself can go on.
        }
    }

    private function quitarLanzando(): void
    {
        @unlink($this->rutaLanzando());
    }

    private function rutaLanzando(): string
    {
        return rtrim((string) config('coder.directorio'), '/').'/lanzando';
    }

    private function rutaDestino(): string
    {
        return rtrim((string) config('coder.directorio'), '/').'/destino.json';
    }

    private function rutaPaquetes(): string
    {
        return rtrim((string) config('coder.directorio'), '/').'/'.config('coder.paquete.subcarpeta');
    }

    /** Write a file by rename in the same directory: a reader sees the old file or the new file, never half of it. */
    public static function escribirAtomico(string $ruta, string $contenido, int $modo): void
    {
        $temporal = $ruta.'.tmp'.bin2hex(random_bytes(4));
        // Create the file with its final mode first: a secret must never be readable for a moment.
        if (@touch($temporal) === false || @chmod($temporal, $modo) === false) {
            @unlink($temporal);
            throw new RuntimeException("no se puede escribir en {$temporal}");
        }
        if (@file_put_contents($temporal, $contenido, LOCK_EX) === false || ! @rename($temporal, $ruta)) {
            @unlink($temporal);
            throw new RuntimeException("no se puede escribir {$ruta}");
        }
    }
}
