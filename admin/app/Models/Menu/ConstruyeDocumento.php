<?php

namespace App\Models\Menu;

use App\Enums\DiaSemana;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait ConstruyeDocumento
{
    /**
     * Ingredientes de las opciones ELEGIDAS, sumados por nombre y unidad. `comprar` lleva los que no están en casa
     * (o sin dato de stock) y `en_casa` los que el encargo daba como existentes.
     *
     * @return array{comprar: list<array{nombre: string, cantidad: ?float, unidad: string}>, en_casa: list<array{nombre: string, cantidad: ?float, unidad: string}>}
     */
    public function listaCompra(): array
    {
        $acumulado = [];
        foreach ($this->contenido['dias'] ?? [] as $dia) {
            foreach (['comida', 'cena'] as $momento) {
                foreach ($this->opcionElegida($dia['fecha'], $momento)['ingredientes'] ?? [] as $ing) {
                    $nombre = trim((string) ($ing['nombre'] ?? ''));
                    if ($nombre === '') {
                        continue;
                    }
                    $unidad = trim((string) ($ing['unidad'] ?? ''));
                    $clave = mb_strtolower($nombre).'|'.mb_strtolower($unidad);
                    $cantidad = is_numeric($ing['cantidad'] ?? null) ? (float) $ing['cantidad'] : null;
                    $previo = $acumulado[$clave] ?? ['nombre' => $nombre, 'cantidad' => null, 'unidad' => $unidad, 'en_casa' => true];
                    $acumulado[$clave] = [
                        'cantidad' => $cantidad === null ? $previo['cantidad'] : ($previo['cantidad'] ?? 0.0) + $cantidad,
                        'en_casa' => $previo['en_casa'] && ($ing['en_stock'] ?? false) === true,
                    ] + $previo;
                }
            }
        }

        $ordenados = collect($acumulado)->sortBy(fn (array $i): string => Str::ascii(mb_strtolower($i['nombre'])))->values();
        $limpio = fn (array $i): array => ['nombre' => $i['nombre'], 'cantidad' => $i['cantidad'], 'unidad' => $i['unidad']];

        return [
            'comprar' => $ordenados->reject(fn (array $i): bool => $i['en_casa'])->map($limpio)->values()->all(),
            'en_casa' => $ordenados->filter(fn (array $i): bool => $i['en_casa'])->map($limpio)->values()->all(),
        ];
    }

    /**
     * Todo lo que necesita la vista del PDF: una opción por toma (la elegida), la lista de la compra y los datos de los perfiles.
     *
     * @return array<string, mixed>
     */
    public function datosDocumento(): array
    {
        $dias = [];
        foreach ($this->contenido['dias'] ?? [] as $dia) {
            $fecha = CarbonImmutable::parse($dia['fecha']);
            $tomas = [];
            foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta) {
                $opcion = $this->opcionElegida($dia['fecha'], $momento);
                $tomas[$momento] = [
                    'etiqueta' => $etiqueta, 'opcion' => $opcion, 'alternativas' => count($this->opcionesDe($dia['fecha'], $momento)),
                    'minutos' => $this->minutosDelHogar($dia['fecha'], $momento),
                ];
            }
            $dias[] = ['fecha' => $fecha, 'inicial' => DiaSemana::deFecha($fecha)->inicial(), 'nombre' => $fecha->locale('es')->isoFormat('dddd D [de] MMM'), 'tomas' => $tomas];
        }

        $inicio = CarbonImmutable::parse($this->fecha_inicio);

        return [
            'titulo' => $this->nombre,
            'hogar' => $this->hogar->nombre,
            'inicio' => $inicio,
            'fin' => $inicio->addDays(max(0, count($dias) - 1)),
            'perfiles' => $this->encargo['perfiles'] ?? [],
            'dias' => $dias,
            'compra' => $this->listaCompra(),
            'tomas' => $this->totalTomas(),
            'generado' => CarbonImmutable::now(),
        ];
    }

    /** El PDF del menú con la opción elegida en cada toma. Se genera cada vez, así siempre refleja las elecciones actuales. */
    public function generarPdf(): string
    {
        $datos = $this->datosDocumento();
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
