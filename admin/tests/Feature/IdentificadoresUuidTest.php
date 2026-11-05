<?php

namespace Tests\Feature;

use App\Models\AgenteArchivo;
use App\Models\Ajuste;
use App\Models\Alimento;
use App\Models\Auditoria;
use App\Models\ChatArchivo;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\User;
use App\Services\Instancia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ApiChat;
use Tests\TestCase;

/**
 * Todas las claves primarias son UUID: dos bifurcaciones de la base no chocan al fusionarse. Las filas con clave natural unica
 * (alimento, ajuste, archivo del agente, archivo del chat) derivan el UUID de esa clave (UUIDv5) y coinciden entre bifurcaciones.
 */
class IdentificadoresUuidTest extends TestCase
{
    use ApiChat;
    use RefreshDatabase;

    public function test_los_modelos_con_id_propio_lo_reciben_como_uuid_v7(): void
    {
        $hogar = Hogar::create(['nombre' => 'Casa']);
        $perfil = Perfil::create(['hogar_id' => $hogar->id, 'nombre' => 'Marta']);
        $usuario = User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']);
        $menu = Menu::factory()->create(['hogar_id' => $hogar->id]);

        foreach ([$hogar, $perfil, $usuario, $menu] as $modelo) {
            $this->assertTrue(Str::isUuid($modelo->id), $modelo::class.' no tiene un UUID');
            $this->assertSame(7, (int) $modelo->id[14], $modelo::class.' no es UUIDv7');
        }
        $this->assertSame($hogar->id, $perfil->hogar_id);
    }

    public function test_el_id_de_una_clave_natural_es_el_mismo_en_dos_bifurcaciones(): void
    {
        $uno = Alimento::create(['nombre' => 'Leche', 'categoria' => 'lacteo', 'unidad' => 'l']);
        $uno->delete();
        $otro = Alimento::create(['nombre' => 'Leche', 'categoria' => 'lacteo', 'unidad' => 'l']);

        $this->assertSame($uno->id, $otro->id);
        $this->assertSame(Alimento::idDeClave('Leche'), $otro->id);
        $this->assertNotSame(Alimento::idDeClave('Leche'), Alimento::idDeClave('Pan'));
    }

    public function test_cada_tabla_tiene_su_propio_espacio_de_nombres(): void
    {
        $this->assertNotSame(Alimento::idDeClave('x'), Ajuste::idDeClave('x'));
        $this->assertNotSame(AgenteArchivo::idDeClave('x'), ChatArchivo::idDeClave('x'));
    }

    public function test_renombrar_no_cambia_el_id(): void
    {
        $alimento = Alimento::create(['nombre' => 'Leche', 'categoria' => 'lacteo', 'unidad' => 'l']);
        $alimento->update(['nombre' => 'Leche entera']);

        $this->assertSame(Alimento::idDeClave('Leche'), $alimento->fresh()->id);
    }

    public function test_ajustes_y_archivos_del_agente_derivan_su_id_de_la_clave(): void
    {
        Ajuste::fijar(Ajuste::CHAT_HABILITADO, false);
        $archivo = AgenteArchivo::create(['ruta' => 'encargos/uno.json', 'contenido' => 'e30=']);

        $this->assertSame(Ajuste::idDeClave(Ajuste::CHAT_HABILITADO), Ajuste::query()->where('clave', Ajuste::CHAT_HABILITADO)->value('id'));
        $this->assertSame(AgenteArchivo::idDeClave('encargos/uno.json'), $archivo->id);
    }

    public function test_un_archivo_subido_al_chat_se_identifica_por_su_hash(): void
    {
        $this->prepararChat();
        $bytes = $this->bytesPng();
        $hash = hash('sha3-256', $bytes);

        $this->subir($bytes)->assertOk();

        $this->assertSame(ChatArchivo::idDeClave($hash), ChatArchivo::query()->where('hash', $hash)->value('id'));
    }

    public function test_la_auditoria_anota_la_instancia_y_el_id_del_modelo(): void
    {
        config(['nutrition.instancia' => 'portatil-de-marta']);
        Instancia::olvidar();

        $hogar = Hogar::create(['nombre' => 'Casa']);

        $fila = Auditoria::query()->where('auditable_type', Hogar::class)->firstOrFail();
        $this->assertSame('portatil-de-marta', $fila->instancia);
        $this->assertSame($hogar->id, $fila->auditable_id);
        $this->assertTrue(Str::isUuid($fila->id));

        Instancia::olvidar();
    }

    public function test_sin_instancia_configurada_se_genera_una_y_se_conserva(): void
    {
        config(['nutrition.instancia' => null]);
        Instancia::olvidar();
        $ruta = storage_path('app/instancia');
        $existia = is_file($ruta) ? file_get_contents($ruta) : null;
        @unlink($ruta);

        try {
            $id = Instancia::id();
            $this->assertTrue(Str::isUuid((string) $id));
            Instancia::olvidar();
            $this->assertSame($id, Instancia::id());
        } finally {
            $existia === null ? @unlink($ruta) : file_put_contents($ruta, $existia);
            Instancia::olvidar();
        }
    }
}
