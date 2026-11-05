<?php

namespace Tests\Feature;

use App\Filament\Resources\Auditorias\AuditoriaResource;
use App\Models\Auditoria;
use App\Models\Hogar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']);
    }

    public function test_crear_actualizar_y_borrar_un_modelo_generan_filas_con_usuario(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario);

        $hogar = Hogar::create(['nombre' => 'Casa']);
        $hogar->update(['nombre' => 'Piso']);
        $hogar->delete();

        $filas = Auditoria::where('auditable_type', Hogar::class)->orderBy('id')->get();
        $this->assertSame(['creado', 'actualizado', 'eliminado'], $filas->pluck('evento')->all());
        $this->assertSame([$usuario->id, $usuario->id, $usuario->id], $filas->pluck('user_id')->all());
        $this->assertSame('Casa', $filas[1]->valores_antiguos['nombre']);
        $this->assertSame('Piso', $filas[1]->valores_nuevos['nombre']);
    }

    public function test_la_contrasena_nunca_aparece_en_los_valores(): void
    {
        $this->usuario();
        $usuario = User::first();
        $usuario->update(['password' => 'otra-clave-secreta']);

        foreach (Auditoria::where('auditable_type', User::class)->get() as $fila) {
            $json = json_encode([$fila->valores_antiguos, $fila->valores_nuevos]);
            $this->assertStringNotContainsString('password', str_replace('"password":"***"', '', (string) $json));
            $this->assertStringNotContainsString('otra-clave-secreta', (string) $json);
            $this->assertStringNotContainsString('$2y$', (string) $json);
        }
    }

    public function test_registrar_enmascara_datos_sensibles(): void
    {
        Auditoria::registrar('prueba', null, ['ANTHROPIC_API_KEY' => 'sk-123', 'anidado' => ['token' => 'abc'], 'ok' => 'visible']);

        $fila = Auditoria::where('evento', 'prueba')->firstOrFail();
        $this->assertSame('***', $fila->datos['ANTHROPIC_API_KEY']);
        $this->assertSame('***', $fila->datos['anidado']['token']);
        $this->assertSame('visible', $fila->datos['ok']);
    }

    public function test_login_logout_y_login_fallido_generan_filas(): void
    {
        $usuario = $this->usuario();

        Auth::attempt(['email' => 'a@b.c', 'password' => 'mala']);
        Auth::login($usuario);
        Auth::logout();

        $eventos = Auditoria::whereIn('evento', ['login', 'logout', 'login_fallido'])->pluck('evento')->all();
        $this->assertContains('login_fallido', $eventos);
        $this->assertContains('login', $eventos);
        $this->assertContains('logout', $eventos);

        $fallido = Auditoria::where('evento', 'login_fallido')->firstOrFail();
        $this->assertSame('a@b.c', $fallido->datos['email']);
        $this->assertStringNotContainsString('mala', (string) json_encode($fallido->datos));
    }

    public function test_registrar_no_lanza_sin_peticion_ni_usuario(): void
    {
        Auditoria::registrar('copia.exportada', 'Sin usuario');

        $this->assertSame(1, Auditoria::where('evento', 'copia.exportada')->count());
        $this->assertNull(Auditoria::where('evento', 'copia.exportada')->first()->user_id);
    }

    public function test_el_modelo_es_inmutable(): void
    {
        Auditoria::registrar('prueba');
        $fila = Auditoria::where('evento', 'prueba')->firstOrFail();

        try {
            $fila->update(['evento' => 'otro']);
            $this->fail('Debia lanzar LogicException al actualizar.');
        } catch (LogicException) {
            $this->assertSame('prueba', $fila->fresh()->evento);
        }

        try {
            $fila->delete();
            $this->fail('Debia lanzar LogicException al borrar.');
        } catch (LogicException) {
            $this->assertNotNull($fila->fresh());
        }
    }

    public function test_el_resource_es_de_solo_lectura(): void
    {
        $this->assertFalse(AuditoriaResource::canCreate());
        $this->assertFalse(AuditoriaResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(AuditoriaResource::getPages()));

        $fila = new Auditoria;
        $this->assertFalse(AuditoriaResource::canEdit($fila));
        $this->assertFalse(AuditoriaResource::canDelete($fila));
    }

    public function test_un_usuario_autenticado_puede_listar_y_ver(): void
    {
        $usuario = $this->usuario();
        Auditoria::registrar('copia.exportada', 'Exportada la base');
        $fila = Auditoria::where('evento', 'copia.exportada')->firstOrFail();

        $this->actingAs($usuario)->get('/auditorias')->assertOk()->assertSee('Exportada la base');
        $this->actingAs($usuario)->get('/auditorias/'.$fila->id)->assertOk();
    }
}
