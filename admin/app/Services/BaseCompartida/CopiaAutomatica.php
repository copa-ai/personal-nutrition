<?php

namespace App\Services\BaseCompartida;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Copia de seguridad periodica de la base en la que se escribe (`nutrition:vigilar-base`), por si la base muere: un volcado `.sql.gz`
 * (el mismo de CopiaDeRetirada y de la pagina de copia de seguridad) en `nutrition.copias_automaticas.carpeta`.
 *
 * Cuando se hace: nada mas ver una base que no tiene copia (la primera vuelta tras conectar con ella, o una base nueva) y despues, como
 * mucho una vez cada `intervalo_horas`, solo si la auditoria tiene eventos nuevos desde la ultima copia. Lo recordado va en `<estado>.copias`
 * (por identificador de base). Una base nueva y vacia no desplaza a las copias buenas antes de lo normal: cuenta como una copia mas y las
 * copias se podan por antiguedad, y se conservan `guardadas`.
 */
class CopiaAutomatica
{
    public function __construct(private readonly CopiaDeRetirada $copia = new CopiaDeRetirada) {}

    /**
     * @return ?string ruta de la copia hecha; null si no tocaba hacerla
     *
     * @throws RuntimeException si tocaba y no se pudo guardar entera
     */
    public function tomarSiToca(Candidata $base, string $fichero, ?CarbonImmutable $ahora = null): ?string
    {
        if (! config('nutrition.copias_automaticas.activa')) {
            return null;
        }
        $ahora ??= CarbonImmutable::now();
        $recuerdo = $this->recuerdo($fichero);
        $ultima = $recuerdo[$base->identificador] ?? null;

        if ($ultima !== null) {
            $cadaS = max(0, (int) config('nutrition.copias_automaticas.intervalo_horas')) * 3600;
            if ($base->eventos <= (int) $ultima['eventos'] || $ahora->getTimestamp() - (int) $ultima['en'] < $cadaS) {
                return null;
            }
        }

        $ruta = $this->copia->guardar(
            $base->direccion,
            $base->identificador,
            'auto',
            (string) config('nutrition.copias_automaticas.carpeta'),
            (int) config('nutrition.copias_automaticas.guardadas'),
        );
        $recuerdo[$base->identificador] = ['eventos' => $base->eventos, 'en' => $ahora->getTimestamp()];
        file_put_contents($fichero.'.copias', json_encode($recuerdo));

        return $ruta;
    }

    /** @return array<string, array{eventos: int, en: int}> */
    private function recuerdo(string $fichero): array
    {
        $datos = is_file($fichero.'.copias') ? json_decode((string) file_get_contents($fichero.'.copias'), true) : null;

        return is_array($datos) ? $datos : [];
    }
}
