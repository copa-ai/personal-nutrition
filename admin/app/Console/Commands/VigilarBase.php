<?php

namespace App\Console\Commands;

use App\Services\BaseCompartida\Candidata;
use App\Services\BaseCompartida\Eleccion;
use App\Services\BaseCompartida\EstadoBase;
use App\Services\BaseCompartida\Sonda;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use App\Services\Fusion\Fusionador;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Revisa las bases PostgreSQL de la red (las mismas que busca `nutrition:base-datos`), decide en cual escribir (App\Services\BaseCompartida\Eleccion)
 * y, si cambia, reescribe el fichero de estado: la aplicacion se reconecta sola. start.sh lo ejecuta cada pocos minutos.
 *
 * Los datos de cada base que no es la elegida se fusionan en la elegida (App\Services\Fusion\Fusionador; NUTRITION_FUSION_AUTOMATICA, activo por
 * defecto): es idempotente y no toca la base de origen, asi que se repite mientras esta siga recibiendo escrituras (se recuerda que
 * eventos tenia la ultima vez que se fusiono, en «<estado>.fusiones»).
 *
 * Una base que este servicio lanzo (esta en su pool de `celaut`), sobra (no es la elegida y lleva N minutos sin eventos) y ya esta
 * fusionada con todo lo que tiene (nadie ha escrito desde la ultima fusion) se para solo con NUTRITION_BAJA_AUTOMATICA, apagado por defecto.
 *
 * Nunca falla por no ver bases: si ninguna responde deja el estado como esta.
 */
#[Signature('nutrition:vigilar-base {estado : Fichero de estado de la base (el que escribe nutrition:base-datos)}')]
#[Description('Elige la base compartida en la que escribir y, si esta activado, para las que sobran')]
class VigilarBase extends Command
{
    public function handle(Sonda $sonda, Fusionador $fusionador): int
    {
        $fichero = (string) $this->argument('estado');
        $estado = EstadoBase::leer($fichero);
        if ($estado === null) {
            $this->warn("No hay estado de la base en {$fichero}: nada que vigilar.");

            return self::SUCCESS;
        }

        $nodo = Nodo::desdeConfig();
        $pool = $this->pool($nodo);
        $direcciones = array_values(array_unique([
            "{$estado['host']}:{$estado['port']}",
            ...$this->miembros($nodo, $estado['cluster']),
            ...array_column($pool, 'uri'),
        ]));

        [$candidatas, $baseDe] = $this->sondear($sonda, $direcciones, "{$estado['host']}:{$estado['port']}");
        if ($candidatas === []) {
            $this->warn('Ninguna base responde: se deja el estado como esta.');

            return self::SUCCESS;
        }

        $actual = collect($candidatas)->first(fn (Candidata $c): bool => $c->direccion === "{$estado['host']}:{$estado['port']}");
        $eleccion = Eleccion::desdeConfig();
        $elegida = $eleccion->elegir($candidatas, $actual);
        foreach ($candidatas as $c) {
            $this->line("  {$c->direccion}  id {$c->identificador}  eventos {$c->eventos}".($c === $elegida ? '  <- elegida' : ''));
        }

        if ($elegida->direccion !== "{$estado['host']}:{$estado['port']}") {
            [$host, $puerto] = [str($elegida->direccion)->beforeLast(':')->toString(), (int) str($elegida->direccion)->afterLast(':')->toString()];
            EstadoBase::escribir($fichero, $host, $puerto, $estado['cluster']);
            $this->info("Se pasa a escribir en {$elegida->direccion}.");
        }

        $fusionadas = $this->fusionar($sonda, $fusionador, $fichero, $candidatas, $elegida);
        $this->darDeBaja($nodo, $pool, $baseDe, $candidatas, $elegida, $eleccion, $fusionadas);

        return self::SUCCESS;
    }

    /**
     * Una candidata por base: la misma base alcanzable por dos direcciones cuenta una vez (la actual si es ella, si no la primera).
     *
     * @param  list<string>  $direcciones
     * @return array{0: list<Candidata>, 1: array<string, string>} las candidatas y, de cada direccion que respondio, el identificador de su base
     */
    private function sondear(Sonda $sonda, array $direcciones, string $actual): array
    {
        $porBase = [];
        $baseDe = [];
        foreach ($direcciones as $direccion) {
            $candidata = $sonda->sondear(str($direccion)->beforeLast(':')->toString(), (int) str($direccion)->afterLast(':')->toString());
            if ($candidata === null) {
                $this->warn("{$direccion} no responde: se descarta.");

                continue;
            }
            $baseDe[$direccion] = $candidata->identificador;
            if (! isset($porBase[$candidata->identificador]) || $direccion === $actual) {
                $porBase[$candidata->identificador] = $candidata;
            }
        }

        return [array_values($porBase), $baseDe];
    }

    /**
     * Fusiona en la elegida cada base que no lo es y no se ha fusionado ya con lo que tiene. Despues de fusionar se vuelve a mirar la base de
     * origen: si nadie escribio mientras tanto queda anotada como fusionada; si no, se repite en la siguiente vuelta.
     *
     * @param  list<Candidata>  $candidatas
     * @return list<string> identificadores de las bases que estan fusionadas con todo lo que tienen
     */
    private function fusionar(Sonda $sonda, Fusionador $fusionador, string $fichero, array $candidatas, Candidata $elegida): array
    {
        $recuerdo = $this->recuerdo($fichero);
        $fusionadas = [];
        foreach ($candidatas as $c) {
            if ($c->identificador === $elegida->identificador) {
                continue;
            }
            $huella = $this->huella($c);
            if (($recuerdo[$c->identificador] ?? null) === $huella) {
                $fusionadas[] = $c->identificador;

                continue;
            }
            if (! config('nutrition.fusion.activa')) {
                continue;
            }
            try {
                $resumen = $fusionador->fusionarBase($c->direccion, $elegida->direccion);
            } catch (Throwable $e) {
                $this->warn("No se pudo fusionar {$c->direccion} en {$elegida->direccion}: ".$e->getMessage());

                continue;
            }
            $this->info("Se fusiono {$c->direccion} en {$elegida->direccion}: ".json_encode($resumen));
            $despues = $sonda->sondear(str($c->direccion)->beforeLast(':')->toString(), (int) str($c->direccion)->afterLast(':')->toString());
            if ($despues !== null && $this->huella($despues) === $huella) {
                $recuerdo[$c->identificador] = $huella;
                $fusionadas[] = $c->identificador;
            } else {
                $this->line("{$c->direccion} recibio escrituras durante la fusion: se repetira.");
            }
        }
        file_put_contents($fichero.'.fusiones', json_encode($recuerdo));

        return $fusionadas;
    }

    /** Lo que tenia una base la ultima vez que se fusiono: cuantos eventos y cual fue el ultimo. */
    private function huella(Candidata $c): string
    {
        return $c->eventos.'|'.($c->ultimoEvento?->format('Y-m-d H:i:s') ?? '');
    }

    /** @return array<string, string> */
    private function recuerdo(string $fichero): array
    {
        $datos = is_file($fichero.'.fusiones') ? json_decode((string) file_get_contents($fichero.'.fusiones'), true) : null;

        return is_array($datos) ? $datos : [];
    }

    /**
     * @param  list<array{lease: int, uri: string}>  $pool
     * @param  array<string, string>  $baseDe  identificador de la base de cada direccion que respondio
     * @param  list<Candidata>  $candidatas
     * @param  list<string>  $fusionadas  identificadores de las bases fusionadas con todo lo que tienen
     */
    private function darDeBaja(Nodo $nodo, array $pool, array $baseDe, array $candidatas, Candidata $elegida, Eleccion $eleccion, array $fusionadas): void
    {
        $sobrantes = $eleccion->sobrantes($candidatas, $elegida, CarbonImmutable::now(), (int) config('nutrition.baja.silencio_min'));
        foreach ($pool as $instancia) {
            $sobra = collect($sobrantes)->first(fn (Candidata $c): bool => $c->identificador === ($baseDe[$instancia['uri']] ?? null));
            if ($sobra === null) {
                continue;
            }
            if (! in_array($sobra->identificador, $fusionadas, true)) {
                $this->line("La base {$instancia['uri']} sobra, pero aun no esta fusionada con todo lo que tiene: no se para.");

                continue;
            }
            if (! config('nutrition.baja.activa')) {
                $this->line("La base {$instancia['uri']} sobra (sin eventos desde hace {$this->minutos()} min), pero la baja automatica esta desactivada.");

                continue;
            }
            try {
                $nodo->parar((int) $instancia['lease']);
                $this->info("Se para la base {$instancia['uri']}, que sobra.");
            } catch (NodoException $e) {
                $this->warn($e->getMessage());
            }
        }
    }

    private function minutos(): int
    {
        return (int) config('nutrition.baja.silencio_min');
    }

    /** @return list<array{lease: int, uri: string}> */
    private function pool(Nodo $nodo): array
    {
        try {
            return $nodo->instancias();
        } catch (NodoException $e) {
            $this->warn($e->getMessage());

            return [];
        }
    }

    /** @return list<string> */
    private function miembros(Nodo $nodo, string $cluster): array
    {
        $red = config('nutrition.red');
        $direcciones = [];
        try {
            $direcciones = $nodo->miembrosDeRed($red['tag'], $red['protocolo']);
        } catch (NodoException $e) {
            $this->warn($e->getMessage());
        }
        try {
            $direcciones = [...$direcciones, ...$nodo->resolverRed($red['tag'], [...$red['formal'], 'db.cluster' => $cluster], $red['protocolo'])];
        } catch (NodoException $e) {
            $this->warn($e->getMessage());
        }

        return $direcciones;
    }
}
