<?php

namespace App\Services\Chat\Bot;

use App\Enums\CategoriaComida;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\Auditoria;
use App\Models\ChatArchivo;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\RegistroComida;
use Carbon\CarbonImmutable;

/** Crea el registro de lo que se ha comido (con su foto, si la hay) y decide su origen: opción del menú, casa o fuera de casa. */
final class RegistroDeComida
{
    /** Palabras que indican que se comió fuera de casa. */
    private const FUERA = '/\b(fuera|restaurante|bar|cafeteria|kebab|hamburgueseria|pizzeria|comedor|cantina|a domicilio|para llevar|catering|menu del dia|chiringuito)\b/';

    public static function registrar(Perfil $perfil, MomentoComida $momento, CarbonImmutable $fecha, string $descripcion, ?string $fotoHash, ?Menu $menu): RegistroComida
    {
        [$origen, $menuId, $referencia, $categorias] = self::origen($momento, $fecha, $descripcion, $menu);
        $fotoHash = $fotoHash !== null && ChatArchivo::query()->where('hash', $fotoHash)->exists() ? $fotoHash : null;

        $registro = new RegistroComida;
        $registro->fill([
            'perfil_id' => $perfil->id, 'fecha' => $fecha->toDateString(), 'momento' => $momento, 'origen' => $origen, 'descripcion' => $descripcion,
            'categorias' => $categorias, 'opcion_ref' => $referencia, 'foto_hash' => $fotoHash,
        ]);
        $registro->menu_id = $menuId;
        $registro->save();

        Auditoria::registrar('comida.registrada', 'Comida registrada desde el chat', [
            'registro_id' => $registro->id, 'perfil_id' => $perfil->id, 'origen' => $origen->value, 'con_foto' => $fotoHash !== null,
        ]);

        return $registro;
    }

    /** @return array{0: OrigenComida, 1: ?int, 2: ?string, 3: list<string>} origen, menú, «fecha/momento/índice» y categorías */
    private static function origen(MomentoComida $momento, CarbonImmutable $fecha, string $descripcion, ?Menu $menu): array
    {
        $norm = Interprete::normalizar($descripcion);
        if ($menu !== null && $momento !== MomentoComida::Otro) {
            $dia = $fecha->toDateString();
            $opciones = $menu->opcionesDe($dia, $momento->value);
            $indice = null;
            foreach ($opciones as $i => $opcion) {
                $titulo = Interprete::normalizar((string) ($opcion['titulo'] ?? ''));
                if (mb_strlen($titulo) >= 3 && str_contains($norm, $titulo)) {
                    $indice = $i;
                    break;
                }
            }
            if ($indice === null && $opciones !== [] && preg_match('/\b(del menu|segun el menu|como (pone|dice|decia) el menu|lo del menu|el menu)\b/', $norm) === 1) {
                $indice = $menu->indiceElegido($dia, $momento->value);
            }
            if ($indice !== null) {
                $categorias = array_values(array_filter($menu->categoriasDe($dia, $momento->value), fn (mixed $c): bool => is_string($c) && CategoriaComida::tryFrom($c) !== null));

                return [OrigenComida::Menu, $menu->id, "{$dia}/{$momento->value}/{$indice}", $categorias];
            }
        }

        return [preg_match(self::FUERA, $norm) === 1 ? OrigenComida::Fuera : OrigenComida::Casa, null, null, []];
    }
}
