<?php

namespace App\Services\Agente;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Comparte agente/ con el servicio del agente a traves de un bucket S3 (o compatible) en vez de un volumen.
 * Activo solo con AGENTE_S3_BUCKET; sin el, todo son no-op y se trabaja directamente sobre la carpeta (modo volumen).
 * La clave de cada objeto es config('agente.s3.prefijo') + la ruta relativa a agente/. Firma AWS Signature V4 a mano:
 * sin SDK, igual que docker/agente/almacen_s3.py (el otro extremo).
 */
class AlmacenS3
{
    public static function activo(): bool
    {
        return filled(config('agente.s3.bucket'));
    }

    /** Sube carpeta()/$relativa al bucket (si existe). */
    public static function subir(string $carpeta, string $relativa): void
    {
        $ruta = rtrim($carpeta, '/\\').'/'.ltrim($relativa, '/');
        if (! static::activo() || ! is_file($ruta)) {
            return;
        }
        static::exigir(static::peticion('PUT', static::clave($relativa), [], file_get_contents($ruta)), "subir {$relativa}");
    }

    /** Baja $relativa del bucket a carpeta()/$relativa. False si no esta en el bucket. */
    public static function bajar(string $carpeta, string $relativa): bool
    {
        if (! static::activo()) {
            return false;
        }
        $r = static::peticion('GET', static::clave($relativa));
        if ($r->status() === 404) {
            return false;
        }
        static::exigir($r, "bajar {$relativa}");
        $destino = rtrim($carpeta, '/\\').'/'.ltrim($relativa, '/');
        if (! is_dir(dirname($destino)) && ! @mkdir(dirname($destino), 0775, true) && ! is_dir(dirname($destino))) {
            throw new RuntimeException('No se pudo crear la carpeta '.dirname($destino));
        }
        file_put_contents($destino, $r->body());

        return true;
    }

    public static function borrar(string $relativa): void
    {
        if (static::activo()) {
            $r = static::peticion('DELETE', static::clave($relativa));
            if ($r->status() !== 404) {
                static::exigir($r, "borrar {$relativa}");
            }
        }
    }

    /** Baja todos los objetos bajo $relativa/ (p. ej. «base»). */
    public static function bajarCarpeta(string $carpeta, string $relativa): void
    {
        if (! static::activo()) {
            return;
        }
        $prefijo = static::clave(trim($relativa, '/').'/');
        $token = null;
        do {
            $r = static::peticion('GET', '', array_filter(['list-type' => 2, 'prefix' => $prefijo, 'continuation-token' => $token]));
            static::exigir($r, "listar {$relativa}");
            $xml = simplexml_load_string($r->body());
            foreach ($xml->Contents ?? [] as $c) {
                $clave = (string) $c->Key;
                if (! str_ends_with($clave, '/')) {
                    static::bajar($carpeta, static::relativa($clave));
                }
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token);
    }

    /** Sube todos los archivos de carpeta/$relativa/ (p. ej. «base»). */
    public static function subirCarpeta(string $carpeta, string $relativa): void
    {
        $raiz = rtrim($carpeta, '/\\').'/'.trim($relativa, '/');
        if (! static::activo() || ! is_dir($raiz)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                static::subir($carpeta, trim($relativa, '/').'/'.str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1)));
            }
        }
    }

    private static function clave(string $relativa): string
    {
        $prefijo = trim((string) config('agente.s3.prefijo'), '/');
        $relativa = ltrim(str_replace('\\', '/', $relativa), '/');

        return $prefijo !== '' ? $prefijo.'/'.$relativa : $relativa;
    }

    private static function relativa(string $clave): string
    {
        $prefijo = trim((string) config('agente.s3.prefijo'), '/');

        return $prefijo !== '' ? substr($clave, strlen($prefijo) + 1) : $clave;
    }

    private static function exigir($respuesta, string $que): void
    {
        if (! $respuesta->successful()) {
            throw new RuntimeException("S3 {$que} falló ({$respuesta->status()}): ".mb_substr($respuesta->body(), 0, 300));
        }
    }

    /** @param  array<string, mixed>  $consulta */
    private static function peticion(string $metodo, string $clave, array $consulta = [], string $cuerpo = '')
    {
        $c = config('agente.s3');
        $region = $c['region'];
        if ($c['endpoint']) {
            $partes = parse_url(rtrim($c['endpoint'], '/'));
            $esquema = $partes['scheme'];
            $host = $partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
        } else {
            $esquema = 'https';
            $host = $c['ruta_estilo'] ? "s3.{$region}.amazonaws.com" : "{$c['bucket']}.s3.{$region}.amazonaws.com";
        }
        $codificar = fn (string $t) => str_replace('%2F', '/', rawurlencode($t));
        $ruta = '/'.($c['ruta_estilo'] ? $c['bucket'].'/' : '').$codificar($clave);
        ksort($consulta);
        $q = implode('&', array_map(fn ($k, $v) => rawurlencode($k).'='.rawurlencode((string) $v), array_keys($consulta), $consulta));

        $ahora = gmdate('Ymd\THis\Z');
        $fecha = substr($ahora, 0, 8);
        $hash = hash('sha256', $cuerpo);
        $cab = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $ahora];
        $firmadas = implode(';', array_keys($cab));   // ya van en orden alfabetico
        $canonica = implode("\n", [$metodo, $ruta, $q, implode('', array_map(fn ($k, $v) => "{$k}:{$v}\n", array_keys($cab), $cab)), $firmadas, $hash]);
        $alcance = "{$fecha}/{$region}/s3/aws4_request";
        $aFirmar = implode("\n", ['AWS4-HMAC-SHA256', $ahora, $alcance, hash('sha256', $canonica)]);
        $llave = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $fecha, 'AWS4'.$c['secreto'], true), true), true), true);
        unset($cab['host']);
        $cab['Authorization'] = "AWS4-HMAC-SHA256 Credential={$c['clave']}/{$alcance}, SignedHeaders={$firmadas}, Signature=".hash_hmac('sha256', $aFirmar, $llave);

        $http = Http::withHeaders($cab)->timeout(120);
        $url = "{$esquema}://{$host}{$ruta}".($q !== '' ? '?'.$q : '');

        return $metodo === 'PUT' ? $http->withBody($cuerpo, 'application/octet-stream')->put($url) : $http->send($metodo, $url);
    }
}
