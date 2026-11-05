<?php

namespace App\Services\Chat\Bot;

use App\Enums\MomentoComida;
use App\Models\Perfil;
use App\Services\Chat\Marcado\Campo;
use App\Services\Chat\Marcado\Tecla;
use App\Services\Chat\Marcado\Teclado;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Los teclados (bloques keyboard) que ofrece el bot. Todo se genera con Teclado, Campo y Tecla: nunca a mano. */
final class Formularios
{
    /** El teclado de las acciones principales (saludo, ayuda, no entiendo). */
    public static function acciones(): Teclado
    {
        return Teclado::nuevo()
            ->fila(Tecla::de('Menú de hoy', 'menú de hoy')->enviando(), Tecla::de('Menú de mañana', 'menú de mañana')->enviando())
            ->fila(Tecla::de('Registrar lo que he comido', 'quiero registrar lo que he comido')->enviando(), Tecla::de('Despensa', 'qué hay en la despensa')->enviando())
            ->fila(Tecla::de('Ayuda', 'ayuda')->enviando());
    }

    /**
     * Etiqueta de cada perfil para los selects y las órdenes: su nombre, y con «(id)» si dos perfiles se llaman igual.
     *
     * @param  Collection<int, Perfil>  $perfiles
     * @return array<int, string> id => etiqueta (de hasta 80 caracteres)
     */
    public static function etiquetas(Collection $perfiles): array
    {
        $cuenta = $perfiles->countBy(fn (Perfil $p): string => mb_strtolower(trim($p->nombre)));
        $etiquetas = [];
        foreach ($perfiles as $perfil) {
            $nombre = mb_substr(trim($perfil->nombre) === '' ? 'Perfil' : trim($perfil->nombre), 0, 70);
            $etiquetas[$perfil->id] = $cuenta[mb_strtolower(trim($perfil->nombre))] > 1 ? $nombre.' ('.$perfil->id.')' : $nombre;
        }

        return $etiquetas;
    }

    /**
     * Formulario para registrar una comida. Con los datos que se sepan ya puestos.
     *
     * @param  Collection<int, Perfil>  $perfiles
     */
    public static function registro(Collection $perfiles, ?string $perfilId, ?string $momento, CarbonImmutable $fecha, string $descripcion, CarbonImmutable $hoy): Teclado
    {
        $etiquetas = self::etiquetas($perfiles);
        $elegido = $perfilId !== null && isset($etiquetas[$perfilId]) ? $etiquetas[$perfilId] : ($perfiles->count() === 1 ? reset($etiquetas) : null);
        $momento ??= $hoy->hour >= 19 ? MomentoComida::Cena->value : MomentoComida::Comida->value;
        $momentos = [];
        foreach (MomentoComida::cases() as $caso) {
            $momentos[$caso->value] = $caso->getLabel();
        }
        $fecha = $fecha->greaterThan($hoy) ? $hoy : $fecha;

        return Teclado::nuevo()
            ->fila(
                Campo::seleccion('perfil', array_combine($etiquetas, $etiquetas), '¿Quién ha comido?', false, $elegido === null ? [] : [$elegido]),
                Campo::seleccion('momento', $momentos, '¿Qué momento?', false, [$momento]),
            )
            ->fila(
                Campo::fecha('fecha', '¿Qué día?', $fecha->toDateString(), null, $hoy->toDateString()),
                Campo::texto('descripcion', '¿Qué ha comido?', $descripcion === '' ? null : mb_substr($descripcion, 0, 200)),
            )
            ->fila(Tecla::de('Registrar', 'registrar comida perfil={perfil} momento={momento} fecha={fecha}: {descripcion}')->enviando());
    }

    /**
     * Pregunta de qué perfiles quiere el menú. La orden que envía repite el día y los momentos pedidos.
     *
     * @param  Collection<int, Perfil>  $perfiles
     * @param  list<string>  $momentos
     */
    public static function elegirPerfiles(Collection $perfiles, string $dia, array $momentos): Teclado
    {
        $etiquetas = self::etiquetas($perfiles);
        $opciones = ['Todos' => 'Todos'] + array_combine($etiquetas, $etiquetas);

        return Teclado::nuevo()
            ->fila(Campo::seleccion('perfil', $opciones, '¿De quién?', true))
            ->fila(Tecla::de('Ver el menú', trim('menú de '.$dia.' '.implode(' y ', $momentos)).' para {perfil}')->enviando());
    }
}
