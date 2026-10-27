<?php

namespace App\Filament\Resources\RegistrosComida\Schemas;

use App\Enums\CategoriaComida;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Lo que alguien comió de verdad. Es lo que el agente usa como historial: el menú planificado no cuenta como comido.
 *
 * Al crear se elige hogar (si solo hay uno, se rellena solo y se oculta), uno o varios perfiles, fecha y momento. Si el menú activo del
 * hogar programa algo en esa toma, «Qué se comió» sale con esa receta y bloqueado: «Guardar» la da por comida. «Otra cosa» lo vacía y
 * lo habilita para anotar lo que se comió en su lugar. Se crea un registro por perfil. Al editar un registro solo hay un perfil.
 */
class RegistroComidaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Hidden::make('otra_cosa')->default(false)->dehydrated(fn (string $operation): bool => $operation === 'create'),
                // Lo último que se rellenó solo con la receta programada: sirve para no borrar lo que el usuario escribió él.
                Hidden::make('valor_programado')->default(fn (string $operation): ?string => $operation === 'create' ? self::valoresIniciales()['descripcion'] : null)->dehydrated(false),
                Select::make('hogar_id')->label('Hogar')->options(fn (): array => Hogar::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->default(fn (): ?int => self::hogarUnico())->live()->dehydrated(false)->required()
                    ->visible(fn (string $operation): bool => $operation === 'create' && self::hogarUnico() === null)
                    ->afterStateUpdated(function (Get $get, Set $set, string $operation): void {
                        $set('perfiles', []);
                        self::refrescar($get, $set, $operation);
                    }),
                Select::make('perfiles')->label('Perfiles')->multiple()->required()->searchable()->live()
                    ->options(fn (Get $get): array => self::perfilesDelHogar(self::hogarDe(self::estado($get))))
                    ->helperText('Se anota una comida por cada perfil elegido.')
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Select::make('perfil_id')->label('Perfil')->relationship('perfil', 'nombre')->required()->searchable()->preload()
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                DatePicker::make('fecha')->label('Fecha')->required()->default(now())->native(false)->displayFormat('d/m/Y')
                    ->live()->afterStateUpdated(fn (Get $get, Set $set, string $operation) => self::refrescar($get, $set, $operation)),
                Select::make('momento')->label('Momento')->options(MomentoComida::class)->required()->default(MomentoComida::Comida)
                    ->live()->afterStateUpdated(fn (Get $get, Set $set, string $operation) => self::refrescar($get, $set, $operation)),
                Select::make('origen')->label('Origen')->required()->default(OrigenComida::Casa)
                    ->options(fn (string $operation): array => collect($operation === 'edit' ? OrigenComida::cases() : [OrigenComida::Casa, OrigenComida::Fuera])
                        ->mapWithKeys(fn (OrigenComida $o): array => [$o->value => $o->getLabel()])->all())
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' || self::esOtraCosa(self::estado($get)))
                    ->helperText('Si comiste fuera, indícalo: el agente no da por comido lo planificado.'),
                Textarea::make('descripcion')->label('Qué se comió')->required()->rows(3)->columnSpanFull()->dehydrated()
                    ->default(fn (string $operation): ?string => $operation === 'create' ? self::valoresIniciales()['descripcion'] : null)
                    ->disabled(fn (Get $get, string $operation): bool => $operation === 'create' && ! self::esOtraCosa(self::estado($get)))
                    ->helperText(fn (Get $get, string $operation): ?string => $operation === 'create' && ! self::esOtraCosa(self::estado($get))
                        ? 'Es la receta programada en el menú activo. Pulsa «Otra cosa» si se comió algo distinto.' : null),
                CheckboxList::make('categorias')->label('Categorías')->options(CategoriaComida::class)->columns(4)->columnSpanFull()
                    ->default(fn (string $operation): array => $operation === 'create' ? self::valoresIniciales()['categorias'] : [])
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' || self::esOtraCosa(self::estado($get))),
            ]);
    }

    /** Lo que el formulario sabe ahora, en un array, para decidir qué hay programado. */
    public static function estado(Get $get): array
    {
        return ['hogar_id' => $get('hogar_id'), 'perfiles' => $get('perfiles'), 'fecha' => $get('fecha'), 'momento' => $get('momento'), 'otra_cosa' => $get('otra_cosa')];
    }

    /** Id del hogar si solo hay uno; null si hay varios o ninguno. */
    public static function hogarUnico(): ?int
    {
        $ids = Hogar::query()->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** El hogar elegido, el único que haya o el del primer perfil elegido. */
    public static function hogarDe(array $estado): ?int
    {
        if (! empty($estado['hogar_id'])) {
            return (int) $estado['hogar_id'];
        }
        if (($unico = self::hogarUnico()) !== null) {
            return $unico;
        }
        $perfil = $estado['perfiles'][0] ?? null;

        return $perfil ? Perfil::query()->whereKey($perfil)->value('hogar_id') : null;
    }

    /** @return array<int, string> */
    private static function perfilesDelHogar(?int $hogarId): array
    {
        return $hogarId ? Perfil::query()->where('hogar_id', $hogarId)->orderBy('nombre')->pluck('nombre', 'id')->all() : [];
    }

    /**
     * La receta que el menú activo del hogar tiene programada (la opción elegida, con los cambios vigentes) en esa fecha y momento.
     * Null si no hay menú activo, el momento no es comida ni cena o ese día no se cocina.
     *
     * @return ?array{menu: Menu, ref: string, titulo: string, categorias: list<string>}
     */
    public static function programadaDe(array $estado): ?array
    {
        $momento = $estado['momento'] instanceof MomentoComida ? $estado['momento']->value : $estado['momento'];
        $hogarId = self::hogarDe($estado);
        if (! $hogarId || empty($estado['fecha']) || ! in_array($momento, ['comida', 'cena'], true)) {
            return null;
        }
        $menu = Hogar::query()->find($hogarId)?->menuActivo;
        if ($menu === null || empty($menu->contenido)) {
            return null;
        }
        $fecha = CarbonImmutable::parse($estado['fecha'])->toDateString();
        $opcion = $menu->opcionElegida($fecha, $momento);
        if ($opcion === null) {
            return null;
        }

        return ['menu' => $menu, 'ref' => "{$fecha}/{$momento}/{$menu->indiceElegido($fecha, $momento)}",
            'titulo' => (string) ($opcion['titulo'] ?? ''), 'categorias' => array_values($opcion['categorias'] ?? [])];
    }

    /** Se anota otra cosa: el usuario lo pidió con «Otra cosa» o no hay nada programado que dar por comido. */
    public static function esOtraCosa(array $estado): bool
    {
        return ! empty($estado['otra_cosa']) || self::programadaDe($estado) === null;
    }

    /**
     * Valores de «Qué se comió» y categorías cuando se da por comido lo programado (vacíos si no hay nada programado).
     *
     * @return array{descripcion: string, categorias: list<string>}
     */
    public static function valoresProgramados(array $estado): array
    {
        $programada = self::programadaDe($estado);

        return ['descripcion' => $programada['titulo'] ?? '', 'categorias' => $programada['categorias'] ?? []];
    }

    /**
     * Lo programado para hoy (comida) al abrir el formulario, con el hogar único si lo hay: así «Qué se comió» no sale vacío y bloqueado
     * hasta que se toque la fecha o el momento.
     *
     * @return array{descripcion: string, categorias: list<string>}
     */
    public static function valoresIniciales(): array
    {
        return self::valoresProgramados(['hogar_id' => null, 'perfiles' => [], 'fecha' => now()->toDateString(), 'momento' => MomentoComida::Comida->value, 'otra_cosa' => false]);
    }

    /**
     * Al cambiar hogar, fecha o momento (solo al crear): si se sigue lo programado, el campo pasa a la receta de esa toma. En «otra cosa» no se toca.
     * Si en la toma nueva no hay nada programado solo se vacía lo que se había rellenado solo con la receta anterior, nunca lo escrito a mano.
     */
    private static function refrescar(Get $get, Set $set, string $operation): void
    {
        $estado = self::estado($get);
        if ($operation !== 'create' || ! empty($estado['otra_cosa'])) {
            return;
        }
        $programada = self::programadaDe($estado);
        if ($programada === null && (string) $get('descripcion') !== (string) $get('valor_programado')) {
            return;
        }
        foreach (self::valoresProgramados($estado) as $campo => $valor) {
            $set($campo, $valor);
        }
        $set('valor_programado', $programada['titulo'] ?? '');
    }
}
