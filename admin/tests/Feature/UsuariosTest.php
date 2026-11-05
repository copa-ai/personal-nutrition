<?php

namespace Tests\Feature;

use App\Filament\Resources\Usuarios\Pages\CreateUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsuariosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $usuario = User::create(['name' => 'Admin', 'email' => 'admin@b.c', 'password' => 'password']);
        $this->actingAs($usuario);

        return $usuario;
    }

    public function test_crear_usuario(): void
    {
        $this->admin();

        Livewire::test(CreateUsuario::class)
            ->fillForm([
                'name' => 'Nuevo',
                'email' => 'nuevo@b.c',
                'password' => 'clave-segura-1',
                'password_confirmation' => 'clave-segura-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(User::where('email', 'nuevo@b.c')->first());
    }

    public function test_editar_sin_contrasena_conserva_el_hash(): void
    {
        $this->admin();
        $otro = User::create(['name' => 'Otro', 'email' => 'otro@b.c', 'password' => 'password']);
        $hash = $otro->password;

        Livewire::test(EditUsuario::class, ['record' => $otro->getKey()])
            ->fillForm(['name' => 'Otro editado', 'password' => '', 'password_confirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $otro->refresh();
        $this->assertSame('Otro editado', $otro->name);
        $this->assertSame($hash, $otro->password);
    }

    public function test_editar_con_contrasena_la_cambia(): void
    {
        $this->admin();
        $otro = User::create(['name' => 'Otro', 'email' => 'otro@b.c', 'password' => 'password']);
        $hash = $otro->password;

        Livewire::test(EditUsuario::class, ['record' => $otro->getKey()])
            ->fillForm(['password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $otro->refresh();
        $this->assertNotSame($hash, $otro->password);
        $this->assertTrue(password_verify('clave-nueva-123', $otro->password));
    }

    public function test_no_se_puede_borrar_a_si_mismo(): void
    {
        $yo = $this->admin();
        User::create(['name' => 'Otro', 'email' => 'otro@b.c', 'password' => 'password']);

        $this->assertFalse($yo->puedeBorrarse());
        $this->assertFalse($yo->delete());
        $this->assertDatabaseHas('users', ['id' => $yo->getKey()]);
    }

    public function test_no_se_puede_borrar_al_ultimo_usuario(): void
    {
        $yo = $this->admin();
        $this->actingAs(User::make(['name' => 'X'])->forceFill(['id' => 9999]));

        $this->assertFalse($yo->puedeBorrarse());
        $this->assertFalse($yo->delete());
        $this->assertSame(1, User::count());
    }

    public function test_se_puede_borrar_a_otro_usuario(): void
    {
        $this->admin();
        $otro = User::create(['name' => 'Otro', 'email' => 'otro@b.c', 'password' => 'password']);

        $this->assertTrue($otro->delete());
        $this->assertSame(1, User::count());
    }

    public function test_correo_duplicado_rechazado(): void
    {
        $this->admin();

        Livewire::test(CreateUsuario::class)
            ->fillForm([
                'name' => 'Duplicado',
                'email' => 'admin@b.c',
                'password' => 'clave-segura-1',
                'password_confirmation' => 'clave-segura-1',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }
}
