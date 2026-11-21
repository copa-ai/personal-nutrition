<?php

namespace App\Services\Coder;

use RuntimeException;

/**
 * Make an ed25519 SSH host key in the OpenSSH private key format, with the PHP sodium extension.
 * The image has no ssh-keygen, and this needs no new package and no sub-process.
 * The key has no passphrase. The Coder service gets it in the variable CODER_SSH_HOST_KEY.
 */
class ClaveSshHost
{
    private const TIPO = 'ssh-ed25519';

    /** @return string the private key in PEM armor, with a final newline */
    public static function generar(string $comentario = 'nutrition-coder-host'): string
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('Falta la extension sodium de PHP: no se puede generar la clave del servidor SSH.');
        }

        $par = sodium_crypto_sign_keypair();
        $secreta = sodium_crypto_sign_secretkey($par);   // 64 bytes: seed (32) then public key (32)
        $publica = sodium_crypto_sign_publickey($par);

        return static::armar($secreta, $publica, $comentario, random_bytes(4));
    }

    /** Build the file. $comprobacion is the 4-byte value that the format writes twice, to detect a wrong passphrase. */
    public static function armar(string $secreta, string $publica, string $comentario, string $comprobacion): string
    {
        $blobPublico = static::cadena(self::TIPO).static::cadena($publica);

        $privado = $comprobacion.$comprobacion
            .static::cadena(self::TIPO).static::cadena($publica).static::cadena($secreta).static::cadena($comentario);
        // Padding 1, 2, 3... up to a multiple of the cipher block size (8 for the cipher "none").
        for ($i = 1; strlen($privado) % 8 !== 0; $i++) {
            $privado .= chr($i);
        }

        $binario = "openssh-key-v1\0"
            .static::cadena('none').static::cadena('none').static::cadena('')
            .pack('N', 1)
            .static::cadena($blobPublico)
            .static::cadena($privado);

        return "-----BEGIN OPENSSH PRIVATE KEY-----\n"
            .chunk_split(base64_encode($binario), 70, "\n")
            ."-----END OPENSSH PRIVATE KEY-----\n";
    }

    /** SSH wire "string": 4-byte big-endian length, then the bytes. */
    private static function cadena(string $dato): string
    {
        return pack('N', strlen($dato)).$dato;
    }
}
