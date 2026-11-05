<?php

/*
 * El esquema de la base de datos va en el `formal` de la red (claves `db.table.*`); se lee de database/esquema.json, que genera
 * `php artisan nutrition:esquema --escribir` (gramatica en App\Services\EsquemaBaseDatos).
 */
$esquema = json_decode((string) file_get_contents(database_path('esquema.json')), true);
if (! is_array($esquema) || json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException('database/esquema.json no es un JSON valido: '.json_last_error_msg());
}

/*
 * Lo que comparten entre si las instancias de Personal Nutrition que usan la misma base de datos, y como hablan con su nodo Celaut.
 * Ver docker/servicio/start.sh y App\Console\Commands\BaseDatosCompartida.
 */
return [
    // La red Celaut de la base de datos compartida: nutrition-postgres la declara y cada Laravel declara la misma
    // (.service/service.json -> network y dependencies/postgres/.service/service.json). El `formal` describe PostgreSQL con TODO el
    // esquema de las migraciones: dos dispositivos con esquemas distintos estan en redes distintas.
    'red' => [
        'tag' => 'postgres',
        'dependencia' => 'NUTRITION_POSTGRES',   // entrada de .dependencies del servicio empaquetado
        'puerto' => 5432,
        'protocolo' => 'postgresql',            // protocol_stack de la red y del api de la base: de ahi se elige el puerto de un miembro
        // Claves fijas del `formal` (la de seleccion, `db.cluster`, la pone quien instancia). Iguales, byte a byte, a las de los dos
        // service.json (database/sincronizar-esquema.py los reescribe desde esquema.json).
        'formal' => ['db.engine' => 'postgresql', 'db.schema' => 'personal-nutrition', ...$esquema],
    ],

    // Con que usuario/base se crea nutrition-postgres.
    'db' => [
        'usuario' => env('DB_USERNAME', 'nutrition'),
        'nombre' => env('DB_DATABASE', 'personal_nutrition'),
    ],

    // Valores que hay que dar tal cual (nodo execute -e) a cada dispositivo nuevo para que comparta base y datos cifrados.
    // `cluster` es publico (va en el `formal` de la red, que se anuncia a otros nodos); `clave_bd` y `app_key` son secretos.
    'compartido' => [
        'cluster' => env('NUTRITION_DB_CLUSTER') ?: null,
        'clave_bd' => env('DB_PASSWORD') ?: null,
    ],

    // Esperas al arrancar. La base corre emulada si el nodo no es arm64 (minutos para arrancar) y lanzarla se paga del saldo de esta
    // instancia: en vez de salir (y que el nodo reinicie el servicio, lanzando otra base), se espera y se reintenta.
    'espera' => [
        'arranque_s' => (int) env('NUTRITION_ESPERA_ARRANQUE_S', 1800),   // a que una base lanzada acepte conexiones
        'reutilizar_s' => (int) env('NUTRITION_ESPERA_REUTILIZAR_S', 180), // a una base lanzada en un arranque anterior
        'intentos_lanzar' => (int) env('NUTRITION_INTENTOS_LANZAR', 120),   // con saldo insuficiente u otro rechazo transitorio
        'pausa_lanzar_s' => (int) env('NUTRITION_PAUSA_LANZAR_S', 15),
    ],

    'celaut' => [
        'binario' => env('CELAUT_BIN', 'celaut'),
        'raiz' => env('NUTRITION_CELAUT', '/opt/nutrition/celaut'),
        'estado' => env('CELAUT_STATE'),
    ],
];
