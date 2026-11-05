<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Los comandos manuales de exportar y fusionar: lo que se puede comprobar sin una segunda base (el resto, en FusionDatosTest). */
class ExportarFusionarComandosTest extends TestCase
{
    public function test_fusionar_un_fichero_que_no_existe_falla_sin_tocar_nada(): void
    {
        $this->artisan('nutrition:fusionar-datos', ['entrada' => sys_get_temp_dir().'/no-existe-'.uniqid()])
            ->expectsOutputToContain('No se puede leer el fichero')->assertFailed();
    }

    public function test_exportar_a_un_sitio_donde_no_se_puede_escribir_falla(): void
    {
        $this->artisan('nutrition:exportar-datos', ['salida' => '/no-existe-esta-carpeta/datos.ndjson'])
            ->expectsOutputToContain('No se puede crear el fichero')->assertFailed();
    }

    public function test_fusionar_un_fichero_que_no_es_un_volcado_no_cambia_nada(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'datos');
        file_put_contents($ruta, "esto no es un volcado\n");

        try {
            $this->artisan('nutrition:fusionar-datos', ['entrada' => $ruta])->expectsOutputToContain('No se fusiono nada')->assertFailed();
        } finally {
            @unlink($ruta);
        }
    }
}
