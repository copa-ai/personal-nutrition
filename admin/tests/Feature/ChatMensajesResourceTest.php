<?php

namespace Tests\Feature;

use App\Filament\Resources\ChatMensajes\ChatMensajeResource;
use App\Filament\Resources\ChatMensajes\Pages\ListChatMensajes;
use App\Filament\Resources\ChatMensajes\Pages\ViewChatMensaje;
use App\Models\ChatArchivo;
use App\Models\ChatMensaje;
use App\Models\Hogar;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ChatMensajesResourceTest extends TestCase
{
    use RefreshDatabase;

    private function hash(string $bytes): string
    {
        return hash('sha3-256', $bytes);
    }

    private function mensaje(int $sequence, bool $servicio, string $texto, array $extra = []): ChatMensaje
    {
        return ChatMensaje::create($extra + [
            'message_id' => 'm'.$sequence, 'sequence' => $sequence, 'from_service' => $servicio, 'estado' => 'COMPLETED',
            'contenido' => [['tipo' => 'texto', 'texto' => $texto]], 'created_ms' => 1_700_000_000_000 + $sequence, 'updated_ms' => 1_700_000_000_000 + $sequence,
        ]);
    }

    private function entrar(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']));
    }

    public function test_el_resource_es_de_solo_lectura(): void
    {
        $this->entrar();
        $mensaje = $this->mensaje(1, false, 'Hola');

        $this->assertFalse(ChatMensajeResource::canCreate());
        $this->assertFalse(ChatMensajeResource::canEdit($mensaje));
        $this->assertFalse(ChatMensajeResource::canDelete($mensaje));
        $this->assertFalse(ChatMensajeResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(ChatMensajeResource::getPages()));
        $this->get('/chat/create')->assertNotFound();
        $this->get('/chat/'.$mensaje->id.'/edit')->assertNotFound();
    }

    public function test_la_lista_muestra_quien_estado_y_texto_resumido(): void
    {
        $this->entrar();
        $largo = str_repeat('palabra ', 40);
        $a = $this->mensaje(1, false, 'Qué ceno hoy');
        $b = $this->mensaje(2, true, $largo, ['estado' => 'FAILED', 'error' => 'boom']);

        Livewire::test(ListChatMensajes::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertSee('Persona')->assertSee('Asistente')->assertSee('Fallido')
            ->assertSee('Qué ceno hoy')
            ->assertDontSee($largo)
            ->filterTable('estado', 'FAILED')
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        $this->get('/chat')->assertOk();
    }

    public function test_el_detalle_muestra_texto_razonamiento_error_y_fotos(): void
    {
        $this->entrar();
        $hash = $this->hash('bytes');
        $m = $this->mensaje(3, true, '<b>Hola</b> mundo', [
            'estado' => 'FAILED', 'error' => 'Algo falló', 'razonamiento' => 'Pensando en la cena',
            'contenido' => [
                ['tipo' => 'texto', 'texto' => '<b>Hola</b> mundo'],
                ['tipo' => 'imagen', 'hash' => $hash, 'mime' => 'image/png', 'tamano' => 5, 'nombre' => 'a.png', 'alt' => 'Mi plato'],
            ],
        ]);

        $this->get('/chat/'.$m->id)->assertOk()
            ->assertSee('Algo falló')->assertSee('Pensando en la cena')
            ->assertSee('/fotos/'.$hash, false)
            ->assertSee('&lt;b&gt;Hola&lt;/b&gt; mundo', false)
            ->assertDontSee('<b>Hola</b>', false);
        Livewire::test(ViewChatMensaje::class, ['record' => $m->id])->assertOk();
    }

    public function test_exige_sesion(): void
    {
        $m = $this->mensaje(1, false, 'Hola');

        $this->get('/chat')->assertRedirect('/login');
        $this->get('/chat/'.$m->id)->assertRedirect('/login');
    }

    public function test_lo_comido_muestra_la_foto_si_hay_foto_hash(): void
    {
        if (! Schema::hasColumn('registros_comida', 'foto_hash')) {
            Schema::table('registros_comida', fn ($t) => $t->string('foto_hash', 64)->nullable());
        }
        $this->entrar();
        $hogar = Hogar::create(['nombre' => 'Casa']);
        $perfil = Perfil::factory()->create(['hogar_id' => $hogar->id]);
        $hash = $this->hash('foto');
        $con = RegistroComida::factory()->create(['perfil_id' => $perfil->id, 'foto_hash' => $hash]);
        $sin = RegistroComida::factory()->create(['perfil_id' => $perfil->id]);

        $this->get('/lo-comido')->assertOk()->assertSee('/fotos/'.$hash, false);
        $this->get('/lo-comido/'.$con->id.'/edit')->assertOk()->assertSee('/fotos/'.$hash, false);
        $this->get('/lo-comido/'.$sin->id.'/edit')->assertOk()->assertDontSee('/fotos/', false);
    }

    public function test_los_mensajes_no_exponen_secretos(): void
    {
        $this->entrar();
        $m = $this->mensaje(4, true, 'Pega tu clave', ['contenido' => [
            ['tipo' => 'texto', 'texto' => 'Pega tu clave'],
            ['tipo' => 'peticion_secreto', 'request_id' => 'solicitud-secreta-7f3a', 'nombre' => 'CLAUDE_TOKEN', 'prosa' => 'Necesito la clave'],
        ]]);

        $this->get('/chat/'.$m->id)->assertOk()->assertDontSee('solicitud-secreta-7f3a')->assertDontSee('peticion_secreto');
        $this->assertNull(ChatArchivo::query()->first());
    }
}
