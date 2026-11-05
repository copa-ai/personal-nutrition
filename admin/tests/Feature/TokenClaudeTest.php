<?php

namespace Tests\Feature;

use App\Filament\Pages\ConexionClaude;
use App\Models\User;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TokenClaudeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'sk-ant-oat01-token-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_guardar_cifra_el_token_en_disco(): void
    {
        TokenClaude::guardar('  '.self::TOKEN."\n");

        Storage::disk('local')->assertExists('claude-token.enc');
        $this->assertStringNotContainsString(self::TOKEN, Storage::disk('local')->get('claude-token.enc'));
        $this->assertSame(self::TOKEN, TokenClaude::obtener());
        $this->assertTrue(TokenClaude::existe());
    }

    public function test_cambiar_app_key_deja_la_clave_sin_configurar_sin_excepcion(): void
    {
        TokenClaude::guardar(self::TOKEN);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->assertNull(TokenClaude::obtener());
        $this->assertFalse(TokenClaude::existe());
        $this->assertSame([], TokenClaude::entorno());
    }

    public function test_sin_clave_no_hay_entorno_y_quitar_borra_el_fichero(): void
    {
        $this->assertFalse(TokenClaude::existe());
        $this->assertSame([], TokenClaude::entorno());

        TokenClaude::guardar(self::TOKEN);
        $this->assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => self::TOKEN], TokenClaude::entorno());

        TokenClaude::quitar();
        Storage::disk('local')->assertMissing('claude-token.enc');
        $this->assertFalse(TokenClaude::existe());
    }

    public function test_la_pagina_guarda_la_clave_desde_el_panel(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ConexionClaude::class)
            ->mountAction('pegar')
            ->set('mountedActions.0.data.token', self::TOKEN)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(self::TOKEN, TokenClaude::obtener());
    }

    public function test_la_pagina_rechaza_una_clave_demasiado_corta(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ConexionClaude::class)
            ->mountAction('pegar')
            ->set('mountedActions.0.data.token', 'corta')
            ->callMountedAction()
            ->assertHasErrors(['mountedActions.0.data.token' => 'min']);

        $this->assertFalse(TokenClaude::existe());
    }

    public function test_la_pagina_exige_sesion(): void
    {
        $this->get('/conexion-claude')->assertRedirect('/login');
    }
}
