<?php

namespace Tests\Feature;

use App\Filament\Pages\AjustesServicio;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\User;
use App\Services\Menu\TokenClaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AjustesServicioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = 'a@b.c'): User
    {
        return User::create(['name' => 'Admin', 'email' => $email, 'password' => 'password']);
    }

    public function test_valores_por_defecto(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AjustesServicio::class)
            ->assertSet('data.chat_habilitado', true)
            ->assertSet('data.autologin_activo', false);
        $this->assertTrue(Ajuste::chatHabilitado());
        $this->assertFalse((bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
    }

    public function test_usuario_por_defecto_es_admin_demo_si_existe_si_no_el_primero(): void
    {
        $primero = $this->admin('primero@x.y');
        $this->actingAs($primero);
        $this->assertSame($primero->id, AjustesServicio::usuarioElegidoId());

        $demo = $this->admin('admin@demo.com');
        $this->assertSame($demo->id, AjustesServicio::usuarioElegidoId());
        Livewire::test(AjustesServicio::class)->assertSet('data.autologin_usuario_id', $demo->id);
    }

    public function test_apagar_el_chat_persiste_y_se_audita(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AjustesServicio::class)->set('data.chat_habilitado', false);

        $this->assertFalse(Ajuste::chatHabilitado());
        $fila = Auditoria::query()->where('evento', 'chat.interruptor_cambiado')->latest('id')->first();
        $this->assertNotNull($fila);
        $this->assertSame('chat_habilitado', $fila->datos['ajuste']);
        $this->assertFalse($fila->datos['despues']);
    }

    public function test_activar_el_login_automatico_elige_usuario_y_se_audita(): void
    {
        $demo = $this->admin('admin@demo.com');
        $this->actingAs($demo);

        Livewire::test(AjustesServicio::class)->set('data.autologin_activo', true);

        $this->assertTrue((bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO));
        $this->assertSame($demo->id, Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID));
        $this->assertTrue(Auditoria::query()->where('evento', 'ajuste.cambiado')->get()
            ->contains(fn (Auditoria $a): bool => ($a->datos['ajuste'] ?? null) === Ajuste::AUTOLOGIN_ACTIVO && $a->datos['despues'] === true));
    }

    public function test_cambiar_el_usuario_del_login_automatico_persiste(): void
    {
        $this->actingAs($this->admin());
        $otro = $this->admin('otro@x.y');

        Livewire::test(AjustesServicio::class)->set('data.autologin_usuario_id', $otro->id);

        $this->assertSame($otro->id, Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID));
    }

    public function test_la_pagina_muestra_el_aviso_y_el_estado_del_chat(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        ChatMensaje::create(['message_id' => 'm1', 'sequence' => 1, 'contenido' => [], 'estado' => 'COMPLETED', 'created_ms' => 1, 'updated_ms' => 1]);

        $this->get('/ajustes-servicio')->assertOk()
            ->assertSee('Chat habilitado')
            ->assertSee('AVISO DE SEGURIDAD')
            ->assertSee('sin configurar')
            ->assertSee('Mensajes en la conversación');

        TokenClaude::guardar('sk-ant-oat01-token-de-prueba-0123456789');
        $this->get('/ajustes-servicio')->assertOk()->assertSee('configurada');
    }
}
