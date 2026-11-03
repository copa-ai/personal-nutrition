<?php

namespace Tests\Unit;

use App\Services\Celaut\Nodo;
use PHPUnit\Framework\TestCase;

class NodoTest extends TestCase
{
    public function test_elige_el_puerto_con_el_protocolo_pedido(): void
    {
        $miembros = [['slots' => [
            ['internal_port' => 8080, 'transport' => ['tcp'], 'protocol_stack' => [['http1.1'], ['http']], 'uris' => ['10.0.0.9:40080']],
            ['internal_port' => 5432, 'transport' => ['tcp'], 'protocol_stack' => [['postgresql']], 'uris' => ['10.0.0.9:45432']],
        ]]];

        $this->assertSame(['10.0.0.9:45432'], Nodo::direccionesDe($miembros, 'postgresql'));
    }

    public function test_un_miembro_de_otro_nodo_trae_un_puerto_con_la_pila_que_se_pidio(): void
    {
        $miembros = [['slots' => [['internal_port' => 1, 'transport' => ['tcp'], 'protocol_stack' => [['postgresql']], 'uris' => ['203.0.113.7:5432', '198.51.100.2:6000']]]]];

        $this->assertSame(['203.0.113.7:5432', '198.51.100.2:6000'], Nodo::direccionesDe($miembros, 'postgresql'));
    }

    public function test_sin_protocolo_declarado_no_se_adivina_por_el_numero_de_puerto(): void
    {
        $miembros = [['slots' => [['internal_port' => 5432, 'transport' => ['tcp'], 'protocol_stack' => [], 'uris' => ['10.0.0.9:45432']]]]];

        $this->assertSame([], Nodo::direccionesDe($miembros, 'postgresql'));
        $this->assertSame([], Nodo::direccionesDe([], 'postgresql'));
    }

    public function test_quita_duplicados(): void
    {
        $slot = ['internal_port' => 1, 'protocol_stack' => [['postgresql']], 'uris' => ['203.0.113.7:5432']];

        $this->assertSame(['203.0.113.7:5432'], Nodo::direccionesDe([['slots' => [$slot]], ['slots' => [$slot]]], 'postgresql'));
    }
}
