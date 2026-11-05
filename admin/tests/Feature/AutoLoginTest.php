<?php

namespace Tests\Feature;

use App\Http\Middleware\AutoLogin;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoLoginTest extends TestCase
{
    use RefreshDatabase;

    /** Una APP_KEY valida: la peticion cifra cookies con ella. */
    private const CLAVE = 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=';

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => self::CLAVE]);
        $this->usuario = User::create(['name' => 'Demo', 'email' => 'admin@demo.com', 'password' => 'password']);
    }

    private function activar(?int $id = null): void
    {
        Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, $id ?? $this->usuario->id);
        Ajuste::fijar(Ajuste::AUTOLOGIN_ACTIVO, true);
    }

    public function test_inactivo_no_entra_solo(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_activo_entra_con_el_usuario_elegido_y_vuelve_a_la_misma_url(): void
    {
        $this->activar();

        $this->get('/calendario')->assertRedirect(url('/calendario'));
        $this->assertAuthenticatedAs($this->usuario);

        $this->get('/calendario')->assertOk();
        $this->assertTrue(Auditoria::query()->where('evento', 'login_automatico')->exists());
        $this->assertTrue(Auditoria::query()->where('evento', 'login')->exists());
    }

    public function test_entra_tambien_desde_la_pagina_de_login(): void
    {
        $this->activar();

        $this->get('/login')->assertRedirect(url('/login'));
        $this->assertAuthenticatedAs($this->usuario);
        $this->get('/login')->assertRedirect(); // un usuario con sesion no ve el login
    }

    public function test_usuario_inexistente_desactiva_el_ajuste_y_no_entra(): void
    {
        $this->activar(9999);

        $this->get('/')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertFalse((bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
    }

    public function test_borrar_el_usuario_elegido_lo_desactiva_en_la_siguiente_visita(): void
    {
        $otro = User::create(['name' => 'Otro', 'email' => 'otro@x.y', 'password' => 'password']);
        $this->activar($otro->id);
        $otro->delete();

        $this->get('/')->assertRedirect('/login');

        $this->assertFalse((bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
    }

    public function test_login_manual_lo_evita_y_deja_la_cookie(): void
    {
        $this->activar();

        $this->get('/login?manual=1')->assertOk()->assertCookie(AutoLogin::COOKIE_CORTE);
        $this->assertGuest();
    }

    public function test_el_cierre_de_sesion_deja_la_cookie_y_no_hay_bucle(): void
    {
        $this->activar();
        $this->get('/');
        $this->assertAuthenticatedAs($this->usuario);

        $respuesta = $this->post('/logout');
        $respuesta->assertRedirect();
        $respuesta->assertCookie(AutoLogin::COOKIE_CORTE);
        $this->assertGuest();

        // El cliente de pruebas no guarda cookies: el navegador reenviaria la que acaba de recibir.
        $cookie = collect($respuesta->headers->getCookies())->first(fn ($c) => $c->getName() === AutoLogin::COOKIE_CORTE);
        $this->flushSession();
        $this->withCookie(AutoLogin::COOKIE_CORTE, '1');
        $this->assertNotNull($cookie);
        $this->get('/login')->assertOk();
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_la_cookie_de_corte_dura_diez_minutos_y_es_httponly(): void
    {
        $this->activar();
        $this->get('/');
        $respuesta = $this->post('/logout');

        $cookie = collect($respuesta->headers->getCookies())->first(fn ($c) => $c->getName() === AutoLogin::COOKIE_CORTE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(time() + 600, $cookie->getExpiresTime(), 10);
    }

    public function test_si_la_sesion_no_se_conserva_no_hay_bucle_de_redirecciones(): void
    {
        $this->activar();

        // La primera visita entra y redirige; el navegador sin cookies de sesion reenvia solo la de intento.
        $this->get('/calendario')->assertRedirect(url('/calendario'));
        $this->flushSession();
        auth()->logout();
        $this->withCookie(AutoLogin::COOKIE_INTENTO, '1');

        $this->get('/calendario')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_rutas_excluidas_y_peticiones_que_no_son_de_navegador(): void
    {
        $this->activar();

        $this->get('/interno/chat/salud')->assertUnauthorized();
        $this->assertGuest();
        $this->get('/estado')->assertNotFound();
        $this->assertGuest();
        $this->get('/app.log')->assertNotFound();
        $this->assertGuest();
        $this->getJson('/calendario')->assertUnauthorized();
        $this->assertGuest();
        $this->post('/calendario')->assertStatus(405);
        $this->assertGuest();
        $this->get('/calendario', ['X-Livewire' => 'true'])->assertRedirect('/login');
        $this->assertGuest();
    }
}
