<?php

namespace App\Models\Menu;

use App\Enums\DiaSemana;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait ConstruyeDocumento
{
    /**
     * Ingredientes de las opciones ELEGIDAS de comida y cena más los de las tomas individuales (desayuno, almuerzo y merienda
     * de todos los perfiles), sumados por nombre y unidad. `comprar` lleva los que no están en casa (o sin dato de stock)
     * y `en_casa` los que el encargo daba como existentes. Un ingrediente genérico («fruta») sale tal cual.
     *
     * @return array{comprar: list<array{nombre: string, cantidad: ?float, unidad: string}>, en_casa: list<array{nombre: string, cantidad: ?float, unidad: string}>}
     */
    public function listaCompra(): array
    {
        $ordenados = collect($this->necesidadesCompra())->sortBy(fn (array $i): string => Str::ascii(mb_strtolower($i['nombre'])))->values();
        $limpio = fn (array $i): array => ['nombre' => $i['nombre'], 'cantidad' => $i['cantidad'], 'unidad' => $i['unidad']];

        return [
            'comprar' => $ordenados->reject(fn (array $i): bool => $i['en_casa'])->map($limpio)->values()->all(),
            'en_casa' => $ordenados->filter(fn (array $i): bool => $i['en_casa'])->map($limpio)->values()->all(),
        ];
    }

    /**
     * La base de la lista de la compra (la del PDF y la de la tarjeta de cada alimento): los ingredientes de las opciones elegidas de
     * comida y cena y de todas las tomas individuales, sumados por nombre y unidad. Cada uno lleva `en_casa` (el encargo lo daba
     * como existente en todos sus usos) y sus `usos`: dónde se gasta (plato, día, toma, perfil si la toma es individual y cantidad).
     *
     * @return list<array{nombre: string, cantidad: ?float, unidad: string, en_casa: bool, usos: list<array{plato: string, fecha: string, toma: string, perfil: ?string, cantidad: ?float}>}>
     */
    public function necesidadesCompra(): array
    {
        $acumulado = [];
        $suma = function (array $ingredientes, string $plato, string $fecha, string $toma, ?string $perfil) use (&$acumulado): void {
            foreach ($ingredientes as $ing) {
                $nombre = trim((string) ($ing['nombre'] ?? ''));
                if ($nombre === '') {
                    continue;
                }
                $unidad = trim((string) ($ing['unidad'] ?? ''));
                $clave = mb_strtolower($nombre).'|'.mb_strtolower($unidad);
                $cantidad = is_numeric($ing['cantidad'] ?? null) ? (float) $ing['cantidad'] : null;
                $previo = $acumulado[$clave] ?? ['nombre' => $nombre, 'cantidad' => null, 'unidad' => $unidad, 'en_casa' => true, 'usos' => []];
                $acumulado[$clave] = [
                    'cantidad' => $cantidad === null ? $previo['cantidad'] : ($previo['cantidad'] ?? 0.0) + $cantidad,
                    'en_casa' => $previo['en_casa'] && ($ing['en_stock'] ?? false) === true,
                    'usos' => [...$previo['usos'], ['plato' => $plato, 'fecha' => $fecha, 'toma' => $toma, 'perfil' => $perfil, 'cantidad' => $cantidad]],
                ] + $previo;
            }
        };

        foreach ($this->contenidoLeido()['dias'] ?? [] as $dia) {
            foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta) {
                $opcion = $this->opcionElegida($dia['fecha'], $momento);
                $suma($opcion['ingredientes'] ?? [], (string) ($opcion['titulo'] ?? $etiqueta), $dia['fecha'], $etiqueta, null);
            }
            foreach ($this->individualesDelDia($dia) as $perfil) {
                foreach ($perfil['tomas'] as $t) {
                    $suma($t['toma']['ingredientes'] ?? [], (string) ($t['toma']['titulo'] ?? $t['etiqueta']), $dia['fecha'], $t['etiqueta'], $perfil['nombre']);
                }
            }
        }

        return array_values($acumulado);
    }

    /**
     * Todo lo que necesita la vista del PDF: la lista de la compra, y por día una opción por toma (la elegida) más las tomas individuales por perfil, las explicaciones y los datos de los perfiles.
     *
     * Con `$vigente` = false se usa el menú por defecto, sin los cambios que el hogar tenga por intercambio. Cada toma lleva `cambiada`.
     *
     * @return array<string, mixed>
     */
    public function datosDocumento(bool $vigente = true): array
    {
        $previo = $this->leerConCambios;
        $this->conCambios($vigente);
        try {
            return $this->construirDatosDocumento();
        } finally {
            $this->conCambios($previo);
        }
    }

    /** @return array<string, mixed> */
    private function construirDatosDocumento(): array
    {
        $dias = [];
        foreach ($this->contenidoLeido()['dias'] ?? [] as $dia) {
            $fecha = CarbonImmutable::parse($dia['fecha']);
            $tomas = [];
            foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta) {
                $opcion = $this->opcionElegida($dia['fecha'], $momento);
                $tomas[$momento] = [
                    'cambiada' => $this->leerConCambios && in_array("{$dia['fecha']}/{$momento}", $this->tomasCambiadas(), true),
                    'etiqueta' => $etiqueta, 'opcion' => $opcion, 'alternativas' => count($this->opcionesDe($dia['fecha'], $momento)),
                    'minutos' => $this->minutosDelHogar($dia['fecha'], $momento),
                    'categorias' => $this->categoriasDe($dia['fecha'], $momento),
                ];
            }
            $dias[] = ['fecha' => $fecha, 'inicial' => DiaSemana::deFecha($fecha)->inicial(), 'nombre' => $fecha->locale('es')->isoFormat('dddd D [de] MMM'),
                'individuales' => $this->individualesDelDia($dia), 'tomas' => $tomas];
        }

        $inicio = CarbonImmutable::parse($this->fecha_inicio);

        return [
            'titulo' => $this->titulo(),
            'hogar' => $this->hogar->nombre,
            'inicio' => $inicio,
            'fin' => $inicio->addDays(max(0, count($dias) - 1)),
            'perfiles' => $this->encargo['perfiles'] ?? [],
            'vigente' => $this->leerConCambios,
            'dias' => $dias,
            'explicaciones' => $this->explicaciones(),
            'compra' => $this->listaCompra(),
            'tomas' => $this->totalTomas(),
            'generado' => CarbonImmutable::now(),
        ];
    }

    /** El PDF del menú con la opción elegida en cada toma. Se genera cada vez, así siempre refleja las elecciones actuales. `$vigente` = false lo hace con el menú por defecto. */
    public function generarPdf(bool $vigente = true): string
    {
        $datos = $this->datosDocumento($vigente);
        $pdf = Pdf::loadView('pdf.menu', ['d' => $datos])
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'dpi' => 110]);

        // Pie de página con el número de página. Se pone sobre el lienzo ya maquetado: así no hace falta activar la ejecución de PHP en la plantilla.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $lienzo = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->get_font('DejaVu Sans', 'normal');
        $gris = [0.36, 0.41, 0.45];
        $lienzo->page_text(34, $lienzo->get_height() - 28, $datos['titulo'].' · '.$datos['hogar'], $fuente, 6.8, $gris);
        $lienzo->page_text($lienzo->get_width() - 84, $lienzo->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 6.8, $gris);

        return $dompdf->output();
    }

    public function nombreArchivoPdf(): string
    {
        return Str::slug($this->nombre ?: 'menu').'.pdf';
    }
}
