<?php

namespace Tests\Feature;

use App\Services\Coder\LectorEspecificacion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The reader of the spec of an uploaded package: it finds the guest shares that the packed service declares. */
class LectorEspecificacionTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/lector-'.uniqid();
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->carpeta));
        parent::tearDown();
    }

    /** The bytes that a packed service holds for one share policy: the end of the formal, the field tag, the length and the value. */
    private function politica(string $valor): string
    {
        return 'keys=access,env,network,role,tag'."\n".'place=filesystem.directory'."\n".'setting=share"'.chr(strlen($valor)).$valor.chr(0x12);
    }

    private function paquete(string $contenido): string
    {
        $ruta = $this->carpeta.'/servicio.celaut.bee';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function lector(): LectorEspecificacion
    {
        return new LectorEspecificacion;
    }

    public function test_un_paquete_que_declara_la_carpeta_de_invitado_da_su_etiqueta(): void
    {
        $ruta = $this->paquete(random_bytes(500).$this->politica("access=rw\nrole=guest\ntag=project").random_bytes(500));

        $this->assertSame(['project'], $this->lector()->tagsInvitado('paquete', $ruta));
    }

    public function test_la_carpeta_que_el_servicio_exporta_no_es_de_invitado(): void
    {
        $ruta = $this->paquete($this->politica("access=rw\nrole=shared\ntag=project"));

        $this->assertSame([], $this->lector()->tagsInvitado('paquete', $ruta));
    }

    public function test_un_paquete_sin_politicas_no_declara_nada(): void
    {
        $ruta = $this->paquete(random_bytes(2000));

        $this->assertSame([], $this->lector()->tagsInvitado('paquete', $ruta));
    }

    public function test_da_cada_etiqueta_una_vez_y_acepta_las_claves_opcionales(): void
    {
        $ruta = $this->paquete(
            $this->politica("role=guest\ntag=project")
            .$this->politica("access=ro\nenv=CARPETA\nrole=guest\ntag=otra")
            .$this->politica("access=rw\nrole=guest\ntag=project")
            .$this->politica("access=rw\nrole=guest"),
        );

        $this->assertEqualsCanonicalizing(['project', 'otra'], $this->lector()->tagsInvitado('paquete', $ruta));
    }

    public function test_el_texto_suelto_de_un_fichero_de_la_imagen_no_cuenta(): void
    {
        $ruta = $this->paquete("# example\nrole=guest\ntag=project\n");

        $this->assertSame([], $this->lector()->tagsInvitado('paquete', $ruta));
    }

    /** @return array<string, array{int}> */
    public static function posicionesCercaDelLimite(): array
    {
        $casos = [];
        foreach ([0, 1, 100, 1000, 1500, 2000, 2030, 2040, 2047, 2048, 3000, 4090, 4096, 5000] as $posicion) {
            $casos["a partir del byte $posicion"] = [$posicion];
        }

        return $casos;
    }

    #[DataProvider('posicionesCercaDelLimite')]
    public function test_una_politica_que_cruza_el_limite_de_dos_lecturas_se_lee_entera(int $posicion): void
    {
        $politica = $this->politica("access=rw\nrole=guest\ntag=project");
        $relleno = fn (int $n): string => str_repeat('x', $n);
        $ruta = $this->paquete($relleno($posicion).$politica.$relleno(6000 - $posicion));

        $this->assertSame(['project'], $this->lector()->tagsDePaquete($ruta, 2048));
        $this->assertSame(['project'], $this->lector()->tagsDePaquete($ruta, 97));
    }

    public function test_una_politica_al_final_del_fichero_se_lee_entera(): void
    {
        $ruta = $this->paquete(str_repeat('x', 5000).substr($this->politica("role=guest\ntag=project"), 0, -1));

        $this->assertSame(['project'], $this->lector()->tagsDePaquete($ruta, 1024));
    }

    public function test_un_servicio_por_identificador_es_desconocido(): void
    {
        $this->assertNull($this->lector()->tagsInvitado('hash', str_repeat('a', 64)));
    }

    public function test_un_fichero_que_no_se_puede_abrir_es_desconocido(): void
    {
        $this->assertNull($this->lector()->tagsInvitado('paquete', $this->carpeta.'/no-existe.celaut.bee'));
    }
}
