<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_la_raiz_sin_sesion_lleva_al_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
