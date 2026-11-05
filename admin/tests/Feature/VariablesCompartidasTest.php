<?php

namespace Tests\Feature;

use App\Filament\Pages\VariablesCompartidas;
use App\Models\User;
use App\Services\RedCompartida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariablesCompartidasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_muestra_las_variables_y_la_orden_para_otro_dispositivo(): void
    {
        config(['nutrition.compartido.cluster' => 'abc123', 'nutrition.compartido.clave_bd' => 'secreto-bd', 'app.key' => 'base64:clave']);
        $usuario = User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']);

        $this->actingAs($usuario)->get('/variables-compartidas')
            ->assertOk()
            ->assertSee('NUTRITION_RED')
            ->assertSee('-e NUTRITION_RED', false)
            ->assertSee('APP_KEY')
            ->assertSee('NUTRITION_DB_CLUSTER')
            ->assertSee('NUTRITION_DB_PASSWORD')
            ->assertSee('data-qr-nutrition-red', false)
            ->assertSee('vendor/qrcode.min.js', false);
    }

    public function test_decodificar_es_la_inversa_de_codificar(): void
    {
        $red = RedCompartida::codificar('base64:a+/b=', 'abc123', 'p|w|x');

        $this->assertSame(
            ['app_key' => 'base64:a+/b=', 'cluster' => 'abc123', 'clave_bd' => 'p|w|x'],
            RedCompartida::decodificar($red),
        );
    }

    public function test_decodificar_devuelve_null_si_es_invalida(): void
    {
        $this->assertNull(RedCompartida::decodificar(''));
        $this->assertNull(RedCompartida::decodificar('no es base64 !!'));
        $this->assertNull(RedCompartida::decodificar(rtrim(base64_encode('solo|dos'), '=')));
        $this->assertNull(RedCompartida::decodificar(rtrim(base64_encode('a||c'), '=')));
    }

    public function test_sin_valores_no_hay_qr(): void
    {
        config(['nutrition.compartido.cluster' => null, 'nutrition.compartido.clave_bd' => null, 'app.key' => 'k']);
        $usuario = User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']);

        $this->actingAs($usuario)->get('/variables-compartidas')
            ->assertOk()
            ->assertDontSee('data-qr-nutrition-red', false);
    }

    public function test_sin_sesion_no_se_ve(): void
    {
        $this->get('/variables-compartidas')->assertRedirect();
    }

    public function test_la_orden_omite_la_variable_si_falta_algun_valor(): void
    {
        config(['nutrition.compartido.cluster' => null, 'nutrition.compartido.clave_bd' => null, 'app.key' => 'k']);

        $this->assertSame('nodo execute personal-nutrition', VariablesCompartidas::orden());
    }

    public function test_la_red_corta_se_decodifica_con_el_shell_de_start_sh(): void
    {
        $red = RedCompartida::codificar('base64:a+/b=', 'abc123', 'p|w');
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $red);

        $trozo = shell_exec('sed -n "/^if \[ -n \"\${NUTRITION_RED/,/^fi$/p" '.escapeshellarg(base_path('../docker/servicio/start.sh')));
        $this->assertNotEmpty($trozo);
        $salida = shell_exec('fallar() { :; }; NUTRITION_RED='.escapeshellarg($red).'; '.$trozo.' printf "%s\n%s\n%s" "$APP_KEY" "$NUTRITION_DB_CLUSTER" "$NUTRITION_DB_PASSWORD"');

        $this->assertSame("base64:a+/b=\nabc123\np|w", $salida);
    }
}
