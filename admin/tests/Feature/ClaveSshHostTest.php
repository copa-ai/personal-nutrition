<?php

namespace Tests\Feature;

use App\Services\Coder\ClaveSshHost;
use Tests\TestCase;

/** The OpenSSH ed25519 private key that the Coder gets as its host key. */
class ClaveSshHostTest extends TestCase
{
    /** @return array{0: string, 1: string} the decoded binary and the PEM */
    private function generar(): array
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Falta la extension sodium.');
        }
        $pem = ClaveSshHost::generar('prueba');

        return [(string) base64_decode(implode('', array_slice(explode("\n", trim($pem)), 1, -1)), true), $pem];
    }

    public function test_tiene_el_armazon_y_el_formato_de_openssh(): void
    {
        [$binario, $pem] = $this->generar();

        $this->assertStringStartsWith("-----BEGIN OPENSSH PRIVATE KEY-----\n", $pem);
        $this->assertStringEndsWith("-----END OPENSSH PRIVATE KEY-----\n", $pem);
        $this->assertStringStartsWith("openssh-key-v1\0", $binario);
        foreach (array_slice(explode("\n", $pem), 1, -2) as $linea) {
            $this->assertLessThanOrEqual(70, strlen($linea));
        }
    }

    public function test_el_bloque_privado_es_multiplo_de_8_y_repite_la_comprobacion(): void
    {
        [$binario] = $this->generar();

        $p = strlen("openssh-key-v1\0");
        foreach ([4, 4, 4] as $salto) {   // cipher, kdf, kdf options (the last one is empty)
            $largo = unpack('N', substr($binario, $p, 4))[1];
            $p += 4 + $largo;
        }
        $this->assertSame(1, unpack('N', substr($binario, $p, 4))[1]);   // one key
        $p += 4;
        $largoPublico = unpack('N', substr($binario, $p, 4))[1];
        $p += 4 + $largoPublico;
        $largoPrivado = unpack('N', substr($binario, $p, 4))[1];
        $privado = substr($binario, $p + 4);

        $this->assertSame($largoPrivado, strlen($privado));
        $this->assertSame(0, $largoPrivado % 8);
        $this->assertSame(substr($privado, 0, 4), substr($privado, 4, 4));
    }

    public function test_dos_claves_generadas_son_distintas(): void
    {
        [, $a] = $this->generar();
        [, $b] = $this->generar();

        $this->assertNotSame($a, $b);
    }

    public function test_ssh_keygen_lee_la_clave_si_esta_instalado(): void
    {
        [, $pem] = $this->generar();
        $ruta = sys_get_temp_dir().'/hostkey-'.uniqid();
        file_put_contents($ruta, $pem);
        chmod($ruta, 0600);
        exec('command -v ssh-keygen', $salida, $codigo);
        if ($codigo !== 0) {
            @unlink($ruta);
            $this->markTestSkipped('ssh-keygen no esta instalado.');
        }
        exec('ssh-keygen -y -f '.escapeshellarg($ruta).' 2>&1', $publica, $codigo);
        @unlink($ruta);

        $this->assertSame(0, $codigo, implode("\n", $publica));
        $this->assertStringStartsWith('ssh-ed25519 ', $publica[0]);
    }
}
