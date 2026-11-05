<?php

/*
 * Ejecucion del agente desde la administracion: claude -p en la carpeta agente/.
 * El encargo se exporta a agente/encargos/encargo-N.json y el agente deja su menú en
 * agente/workspace/resultado-N.json, que la administracion importa al terminar.
 */
return [
    'carpeta' => env('AGENTE_CARPETA', base_path('../agente')),

    // Donde viven los archivos que se intercambian con el agente (encargos/, workspace/, base/). «base» = en la base de datos
    // (tabla agente_archivos, ver App\Services\Agente\AlmacenAgente): el runner los recibe y devuelve por HTTP y asi los ve cualquier
    // Laravel que comparta la base. Vacio = solo en la carpeta de arriba (desarrollo y pruebas).
    'almacen' => env('AGENTE_ALMACEN') ?: null,

    'claude' => env('AGENTE_CLAUDE', 'claude'),

    // Modelo que se pasa a `claude -p --model` en todas las ejecuciones (la planificacion del menú).
    'modelo' => 'claude-sonnet-5-5',

    // Tarifas en USD por millon de tokens, por modelo. `busqueda_web` es USD por busqueda. Se aplican AL REGISTRAR cada respuesta del
    // modelo (RegistroConsumo) y la tarifa usada se guarda con ella: cambiar esto NO altera lo ya registrado, solo lo que llegue despues.
    // Se casa por modelo exacto o por prefijo (claude-haiku-4-5-2025… usa claude-haiku-4-5). Un modelo que no este aqui queda sin coste. Se puede sobreescribir con AGENTE_PRECIOS (JSON con la misma forma).
    'precios' => json_decode((string) env('AGENTE_PRECIOS'), true) ?: [
        'claude-sonnet-5-5' => ['entrada' => 2.00, 'salida' => 10.00, 'cache_lectura' => 0.20, 'cache_escritura' => 2.50, 'busqueda_web' => 0.01],
        'claude-sonnet-5' => ['entrada' => 2.00, 'salida' => 10.00, 'cache_lectura' => 0.20, 'cache_escritura' => 2.50, 'busqueda_web' => 0.01],
        'claude-opus-5-5' => ['entrada' => 4.00, 'salida' => 20.00, 'cache_lectura' => 0.40, 'cache_escritura' => 5.00, 'busqueda_web' => 0.01],
        'claude-opus-5' => ['entrada' => 5.00, 'salida' => 25.00, 'cache_lectura' => 0.50, 'cache_escritura' => 6.25, 'busqueda_web' => 0.01],
        'claude-haiku-4-5' => ['entrada' => 1.00, 'salida' => 5.00, 'cache_lectura' => 0.10, 'cache_escritura' => 1.25, 'busqueda_web' => 0.01],
    ],

    // Donde corre claude. Con AGENTE_URL (el servicio «agente» de docker-compose, que solo ve agente/) la administracion
    // le pide las ejecuciones por HTTP y no ejecuta claude ella misma. Vacio = lo lanza en el propio proceso (desarrollo, pruebas).
    'remoto' => [
        'url' => env('AGENTE_URL') ?: null,
        'secreto' => env('AGENTE_SECRETO'),
        // Milisegundos entre consultas del avance de una ejecucion.
        'espera_ms' => (int) env('AGENTE_ESPERA_MS', 1000),
    ],

    // Herramientas que se le dejan usar sin preguntar (claude -p no puede pedir permiso a nadie).
    'herramientas' => env('AGENTE_HERRAMIENTAS', 'Read,Write,Edit,Glob,Grep,Bash,WebFetch,WebSearch,Agent'),

    // Forma del menú semanal que se le pide al agente (ver App\Models\Menu\ConstruyeEncargo y ValidaEntrega).
    'menu' => [
        'dias' => (int) env('AGENTE_MENU_DIAS', 7),                    // dias que cubre un menú
        'opciones_min' => 2,                                           // opciones de receta por comida y cena
        'opciones_max' => 3,
        'historial_dias' => (int) env('AGENTE_MENU_HISTORIAL_DIAS', 28), // cuanto de lo comido se le cuenta al agente
        'menus_previos' => 4,                                          // menús anteriores (solo titulos) para no repetir
    ],

    // Segundos que puede tardar una tanda (un ciclo) del menú.
    'timeout' => (int) env('AGENTE_TIMEOUT', 3 * 3600),

    // El menú semanal se genera en UNA sola tanda (max = 1). Se conserva el mecanismo de tandas de la guia por si mas adelante
    // hiciera falta partir el trabajo; con max = 1 nunca se programa una segunda tanda por «no completo».
    'ciclos' => [
        'intervalo_min' => (int) env('AGENTE_CICLO_INTERVALO_MIN', 60), // minutos entre el final de una tanda y el inicio de la siguiente
        'max' => (int) env('AGENTE_CICLOS_MAX', 1),                   // tope de tandas por menú
        'margen_limite_min' => 2,                                     // minutos que se espera de mas tras la hora en que Claude dice que se renueva el limite
    ],
];
