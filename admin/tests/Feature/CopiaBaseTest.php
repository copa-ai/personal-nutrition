<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopiaBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_inicial_solo_se_crea_si_no_hay_usuarios(): void
    {
        $this->artisan('usuario:inicial')->assertSuccessful();
        $this->assertSame(['admin@demo.com'], User::query()->pluck('email')->all());

        User::query()->update(['email' => 'otro@demo.com']);
        $this->artisan('usuario:inicial')->assertSuccessful();
        $this->assertSame(['otro@demo.com'], User::query()->pluck('email')->all());
    }
}
