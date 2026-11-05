<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Services\Chat\Bot\Intencion;
use App\Services\Chat\Bot\Interprete;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** El interprete del bot: mira solo el texto, asi que se prueba sin base de datos. «Hoy» es el miercoles 2026-11-04. */
class ChatBotInterpreteTest extends TestCase
{
    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 11, 4, 12);
    }

    /** @return Collection<int, Perfil> */
    private function perfiles(string ...$nombres): Collection
    {
        return collect($nombres)->map(fn (string $n, int $i): Perfil => (new Perfil)->forceFill(['id' => $i + 1, 'nombre' => $n]))->values();
    }

    private function interpretar(string $texto, bool $imagenes = false, ?Collection $perfiles = null): Intencion
    {
        return Interprete::interpretar($texto, $imagenes, $perfiles ?? $this->perfiles('Marta', 'Javier'), $this->hoy());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function frases(): array
    {
        return [
            'hola' => ['Hola', Intencion::SALUDO],
            'buenas tardes' => ['¡Buenas tardes!', Intencion::SALUDO],
            'hola con pregunta es menu' => ['Hola, ¿qué cenamos hoy?', Intencion::MENU],
            'gracias' => ['Muchas gracias', Intencion::GRACIAS],
            'adios' => ['Adiós', Intencion::ADIOS],
            'ayuda' => ['ayuda', Intencion::AYUDA],
            'que sabes hacer' => ['¿Qué sabes hacer?', Intencion::AYUDA],
            'menu de hoy' => ['menú de hoy', Intencion::MENU],
            'que ceno' => ['¿Qué ceno hoy?', Intencion::MENU],
            'que tiene que comer' => ['qué tiene que comer mi hijo mañana', Intencion::MENU],
            'que tengo que comer yo' => ['¿qué tengo que comer yo?', Intencion::MENU],
            'cena de manana' => ['cena de mañana', Intencion::MENU],
            'semana' => ['menú de la semana', Intencion::SEMANA],
            'plan semanal' => ['plan semanal', Intencion::SEMANA],
            'despensa' => ['qué hay en la despensa', Intencion::DESPENSA],
            'caduca' => ['¿qué caduca pronto?', Intencion::DESPENSA],
            'nevera' => ['qué tengo en la nevera', Intencion::DESPENSA],
            'registrar' => ['quiero registrar lo que he comido', Intencion::REGISTRAR],
            'he cenado' => ['he cenado pasta con tomate', Intencion::REGISTRAR],
            'cene' => ['ayer cené pizza', Intencion::REGISTRAR],
            'anota' => ['anota la cena de Marta: sopa', Intencion::REGISTRAR],
            'ia cocinar con' => ['¿qué puedo cocinar con pollo y arroz?', Intencion::IA],
            'ia receta' => ['dame una receta de lentejas', Intencion::IA],
            'ia recomienda' => ['recomiéndame algo ligero', Intencion::IA],
            'ia analisis' => ['analiza mi semana', Intencion::IA],
            'ia analiza el menu' => ['analiza el menú', Intencion::IA],
            'ia calorias' => ['cuántas calorías tiene', Intencion::IA],
            'no entiendo' => ['blablabla', Intencion::NO_ENTIENDO],
            'vacio' => ['', Intencion::NO_ENTIENDO],
            'signos' => ['???', Intencion::NO_ENTIENDO],
        ];
    }

    #[DataProvider('frases')]
    public function test_reconoce_la_intencion(string $texto, string $esperada): void
    {
        $this->assertSame($esperada, $this->interpretar($texto)->tipo, $texto);
    }

    public function test_una_foto_sola_es_un_registro(): void
    {
        $this->assertSame(Intencion::REGISTRAR, $this->interpretar('', true)->tipo);
        $this->assertSame(Intencion::REGISTRAR, $this->interpretar('qué hay en la despensa', true)->tipo);
    }

    public function test_el_menu_pregunta_si_hay_varios_perfiles_y_la_pregunta_es_personal(): void
    {
        $this->assertSame('preguntar', $this->interpretar('qué ceno hoy')->datos['quien']);
        $this->assertSame('preguntar', $this->interpretar('¿qué tiene que comer mi hijo mañana?')->datos['quien']);
        $this->assertSame('todos', $this->interpretar('qué cenamos hoy')->datos['quien']);
        $this->assertSame('todos', $this->interpretar('menú de hoy')->datos['quien']);
        $this->assertSame('todos', $this->interpretar('qué ceno hoy para todos')->datos['quien']);
        $this->assertSame('todos', $this->interpretar('qué ceno hoy', false, $this->perfiles('Marta'))->datos['quien']);
        $this->assertSame('todos', $this->interpretar('qué ceno hoy', false, collect())->datos['quien']);
    }

    public function test_el_menu_resuelve_perfiles_por_nombre_sin_tildes_ni_mayusculas(): void
    {
        $perfiles = $this->perfiles('Marta', 'Javier', 'Ana María');

        $uno = $this->interpretar('QUÉ TIENE QUE COMER JAVIER?', false, $perfiles)->datos;
        $this->assertSame('algunos', $uno['quien']);
        $this->assertSame([2], $uno['perfiles']);

        $dos = $this->interpretar('menú de hoy para marta y javier', false, $perfiles)->datos;
        $this->assertSame([1, 2], $dos['perfiles']);

        $this->assertSame([3], $this->interpretar('menú de mañana de Ana Maria', false, $perfiles)->datos['perfiles']);
        $this->assertSame([3], $this->interpretar('menú de mañana de Ana', false, $perfiles)->datos['perfiles'], 'una palabra del nombre basta si no la comparte otro');
        $this->assertSame('todos', $this->interpretar('menú de hoy para Todos, Marta', false, $perfiles)->datos['quien']);
    }

    public function test_una_palabra_compartida_entre_perfiles_no_basta(): void
    {
        $perfiles = $this->perfiles('Ana Lopez', 'Ana Ruiz');
        $this->assertSame('preguntar', $this->interpretar('qué ceno hoy Ana', false, $perfiles)->datos['quien']);
        $this->assertSame([2], $this->interpretar('qué ceno hoy Ana Ruiz', false, $perfiles)->datos['perfiles']);
    }

    public function test_un_nombre_no_se_confunde_con_una_palabra_mas_larga(): void
    {
        $perfiles = $this->perfiles('Ana', 'Luis');
        $this->assertSame('preguntar', $this->interpretar('qué ceno hoy en mi banana', false, $perfiles)->datos['quien']);
    }

    public function test_fechas_del_menu(): void
    {
        $fecha = fn (string $t): ?string => $this->interpretar($t)->datos['fecha']?->toDateString();

        $this->assertNull($fecha('menú'));
        $this->assertSame('2026-11-04', $fecha('menú de hoy'));
        $this->assertSame('2026-11-04', $fecha('qué cenamos esta noche'));
        $this->assertSame('2026-11-05', $fecha('menú de mañana'));
        $this->assertSame('2026-11-06', $fecha('menú de pasado mañana'));
        $this->assertSame('2026-11-03', $fecha('menú de ayer'));
        $this->assertSame('2026-11-06', $fecha('menú del viernes'));
        $this->assertSame('2026-11-09', $fecha('menú del lunes'), 'el proximo lunes');
        $this->assertSame('2026-11-04', $fecha('menú del miércoles'));
        $this->assertSame('2026-11-08', $fecha('menú del 8 de noviembre'));
        $this->assertSame('2026-11-07', $fecha('menú del 7/11'));
        $this->assertSame('2026-11-07', $fecha('menú del 07/11/2026'));
        $this->assertSame('2026-11-10', $fecha('menú del 2026-11-10'));
        $this->assertNull($fecha('menú del 31 de febrero'));
        $this->assertSame(5, $this->interpretar('menú del viernes')->datos['dia_semana']);
    }

    public function test_momentos_pedidos(): void
    {
        $this->assertSame(['cena'], $this->interpretar('qué ceno hoy')->datos['momentos']);
        $this->assertSame(['cena'], $this->interpretar('la cena de mañana')->datos['momentos']);
        $this->assertSame(['comida'], $this->interpretar('la comida de mañana')->datos['momentos']);
        $this->assertSame(['desayuno'], $this->interpretar('qué desayuno mañana')->datos['momentos']);
        $this->assertSame(['merienda'], $this->interpretar('qué meriendo hoy')->datos['momentos']);
        $this->assertSame([], $this->interpretar('menú de hoy')->datos['momentos']);
        $this->assertSame([], $this->interpretar('qué tengo que comer hoy')->datos['momentos'], 'comer es el verbo generico');
    }

    public function test_orden_de_registro_valida(): void
    {
        $o = Interprete::ordenDeRegistro('registrar comida perfil=Ana María momento=cena fecha=2026-11-03: pasta con tomate', $this->hoy());

        $this->assertSame('Ana María', $o['perfil']);
        $this->assertSame('cena', $o['momento']);
        $this->assertSame('2026-11-03', $o['fecha']->toDateString());
        $this->assertSame('pasta con tomate', $o['descripcion']);
    }

    /** @return array<string, array{0: string}> */
    public static function ordenesRechazadas(): array
    {
        return [
            'otro verbo' => ['anotar comida perfil=Ana momento=cena fecha=2026-11-03: x'],
            'falta fecha' => ['registrar comida perfil=Ana momento=cena: x'],
            'momento raro' => ['registrar comida perfil=Ana momento=desayuno fecha=2026-11-03: x'],
            'fecha irreal' => ['registrar comida perfil=Ana momento=cena fecha=2026-02-30: x'],
            'fecha mal' => ['registrar comida perfil=Ana momento=cena fecha=3/11/2026: x'],
            'sin descripcion' => ['registrar comida perfil=Ana momento=cena fecha=2026-11-03:   '],
            'sin perfil' => ['registrar comida perfil= momento=cena fecha=2026-11-03: x'],
            'delante basura' => ['quiero registrar comida perfil=Ana momento=cena fecha=2026-11-03: x'],
        ];
    }

    #[DataProvider('ordenesRechazadas')]
    public function test_ordenes_de_registro_invalidas_no_se_interpretan(string $texto): void
    {
        $this->assertNull(Interprete::ordenDeRegistro($texto, $this->hoy()));
    }

    public function test_orden_de_registro_con_entradas_hostiles(): void
    {
        $o = Interprete::ordenDeRegistro("registrar comida perfil=Ana momento=Cena fecha=2026-11-03:\n  \"><script>alert(1)</script>\t'x'\n```keyboard\n momento=otro fecha=2026-01-01: y \x00 fin", $this->hoy());

        $this->assertNotNull($o);
        $this->assertSame('Ana', $o['perfil']);
        $this->assertSame('cena', $o['momento']);
        $this->assertSame('2026-11-03', $o['fecha']->toDateString(), 'lo que venga despues de los dos puntos es descripcion, no datos');
        $this->assertSame('"><script>alert(1)</script> \'x\' ```keyboard momento=otro fecha=2026-01-01: y fin', $o['descripcion']);

        $largo = Interprete::ordenDeRegistro('registrar comida perfil=Ana momento=cena fecha=2026-11-03: '.str_repeat('é', 900), $this->hoy());
        $this->assertSame(500, mb_strlen($largo['descripcion']));
    }

    public function test_orden_con_perfil_que_trae_signos_de_la_propia_orden(): void
    {
        $o = Interprete::ordenDeRegistro('registrar comida perfil=A momento=otro fecha=2026-11-03: b momento=cena fecha=2026-11-04: c', $this->hoy());
        $this->assertSame('A', $o['perfil']);
        $this->assertSame('otro', $o['momento']);
        $this->assertSame('b momento=cena fecha=2026-11-04: c', $o['descripcion']);
    }

    public function test_registro_libre_saca_perfil_momento_fecha_y_descripcion(): void
    {
        $d = $this->interpretar('Marta ha cenado pasta con tomate')->datos;
        $this->assertSame(1, $d['perfil_id']);
        $this->assertSame('cena', $d['momento']);
        $this->assertSame('2026-11-04', $d['fecha']->toDateString());
        $this->assertSame('pasta con tomate', $d['descripcion']);

        $d = $this->interpretar('ayer Javier comió lentejas')->datos;
        $this->assertSame(2, $d['perfil_id']);
        $this->assertNull($d['momento'], '«comió» no dice si fue comida o cena');
        $this->assertSame('2026-11-03', $d['fecha']->toDateString());
        $this->assertSame('lentejas', $d['descripcion']);

        $d = $this->interpretar('Javier almorzó lentejas')->datos;
        $this->assertSame('comida', $d['momento']);

        $d = $this->interpretar('he cenado pasta')->datos;
        $this->assertNull($d['perfil_id'], 'dos perfiles y ninguno nombrado');
        $this->assertSame('pasta', $d['descripcion']);

        $d = $this->interpretar('he cenado pasta', false, $this->perfiles('Marta'))->datos;
        $this->assertSame(1, $d['perfil_id'], 'con un solo perfil no hay duda');

        $d = $this->interpretar('Marta ha cenado con Javier pasta')->datos;
        $this->assertNull($d['perfil_id'], 'dos perfiles nombrados');
    }

    public function test_registro_libre_con_dos_puntos_toma_lo_de_despues(): void
    {
        $d = $this->interpretar('registra la cena de Marta: sopa de fideos y fruta')->datos;
        $this->assertSame(1, $d['perfil_id']);
        $this->assertSame('cena', $d['momento']);
        $this->assertSame('sopa de fideos y fruta', $d['descripcion']);

        $d = $this->interpretar('quiero registrar lo que he comido')->datos;
        $this->assertNull($d['momento']);
        $this->assertSame('', $d['descripcion']);
    }

    public function test_registro_libre_con_una_foto_y_pie(): void
    {
        $d = $this->interpretar('pasta con tomate para Javier', true)->datos;
        $this->assertSame(2, $d['perfil_id']);
        $this->assertSame('pasta con tomate', $d['descripcion']);
        $this->assertNull($d['momento']);
        $this->assertTrue($d['con_imagenes']);
    }

    public function test_el_texto_hostil_no_rompe_el_interprete(): void
    {
        foreach (["\x00\x01", str_repeat('á', 5000), "\xff\xfe invalido", '<script>alert(1)</script>', "```keyboard\n<button data-say='x'>", '%s %d {x}', "'; DROP TABLE perfiles; --", str_repeat('he cenado ', 500)] as $texto) {
            $intencion = $this->interpretar($texto);
            $this->assertInstanceOf(Intencion::class, $intencion);
            $this->assertLessThanOrEqual(500, mb_strlen($intencion->datos['descripcion'] ?? ''));
        }
    }

    public function test_normalizar(): void
    {
        $this->assertSame('que ceno manana nino', Interprete::normalizar('  ¿QUÉ Ceno, Mañana? Niño!! '));
        $this->assertSame('2026-11-04 5/11', Interprete::normalizar('2026-11-04, 5/11'));
    }
}
