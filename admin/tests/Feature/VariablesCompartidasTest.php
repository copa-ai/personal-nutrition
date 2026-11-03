<?php

namespace Tests\Feature;

use App\Filament\Pages\VariablesCompartidas;
use App\Models\User;
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
            ->assertSee('NUTRITION_DB_CLUSTER')
            ->assertSee('abc123')
            ->assertSee("-e APP_KEY &#039;base64:clave&#039;", false);
    }

    public function test_sin_sesion_no_se_ve(): void
    {
        $this->get('/variables-compartidas')->assertRedirect();
    }

    public function test_la_orden_omite_lo_que_no_tiene_valor(): void
    {
        config(['nutrition.compartido.cluster' => null, 'nutrition.compartido.clave_bd' => null, 'app.key' => 'k']);

        $this->assertSame("nodo execute -e APP_KEY 'k' personal-nutrition", VariablesCompartidas::orden());
    }
}
