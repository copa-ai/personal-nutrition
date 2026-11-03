<?php

/*
 * Lo que comparten entre si las instancias de Personal Nutrition que usan la misma base de datos, y como hablan con su nodo Celaut.
 * Ver docker/servicio/start.sh y App\Console\Commands\BaseDatosCompartida.
 */
return [
    // La red Celaut de la base de datos compartida: nutrition-postgres la declara y cada Laravel declara la misma
    // (.service/service.json -> network y dependencies/postgres/.service/service.json).
    'red' => [
        'tag' => 'postgres:personal-nutrition',
        'dependencia' => 'NUTRITION_POSTGRES',   // entrada de .dependencies del servicio empaquetado
        'puerto' => 5432,
        'protocolo' => 'postgresql',            // protocol_stack de la red y del api de la base: de ahi se elige el puerto de un miembro
        // Claves fijas del `formal` (las de seleccion, `db.cluster`, las pone quien instancia). Iguales a las de service.json.
        'formal' => ['db.engine' => 'postgresql', 'db.schema' => 'personal-nutrition'],
    ],

    // Base indicada a mano (DB_HOST/DB_PORT): no se busca ni se lanza nada. Y con que usuario/base se crea nutrition-postgres.
    'db' => [
        'host' => env('DB_HOST') ?: null,
        'port' => (int) env('DB_PORT', 5432),
        'usuario' => env('DB_USERNAME', 'nutrition'),
        'nombre' => env('DB_DATABASE', 'personal_nutrition'),
    ],

    // Valores que hay que dar tal cual (nodo execute -e) a cada dispositivo nuevo para que comparta base y datos cifrados.
    // `cluster` es publico (va en el `formal` de la red, que se anuncia a otros nodos); `clave_bd` y `app_key` son secretos.
    'compartido' => [
        'cluster' => env('NUTRITION_DB_CLUSTER') ?: null,
        'clave_bd' => env('DB_PASSWORD') ?: null,
    ],

    'celaut' => [
        'binario' => env('CELAUT_BIN', 'celaut'),
        'raiz' => env('NUTRITION_CELAUT', '/opt/nutrition/celaut'),
        'estado' => env('CELAUT_STATE'),
    ],
];
