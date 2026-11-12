<?php

/*
 * Modo Claude del chat (App\Services\Chat\Claude): limites de la instantanea de solo lectura que se le da al agente y del turno.
 * Todo tiene un valor por defecto razonable; las variables de entorno solo existen para ajustarlo sin tocar codigo.
 */
return [
    // Segundos que puede tardar un turno de chat (un `claude -p`). Mucho menos que una tanda de menu: la persona espera.
    'timeout' => (int) env('CHAT_CLAUDE_TIMEOUT', 600),

    // Herramientas del turno: solo leer la instantanea y escribir el archivo de acciones (sin Bash ni red).
    'herramientas' => env('CHAT_CLAUDE_HERRAMIENTAS', 'Read,Glob,Grep,Write'),

    // Agente ocupado (AGENTE_MAX_SIMULTANEAS): cuanto se espera, y cada cuanto se reintenta, antes de rendirse con un mensaje claro.
    'espera_agente_max_s' => (int) env('CHAT_CLAUDE_ESPERA_MAX_S', 180),
    'espera_agente_cada_s' => (int) env('CHAT_CLAUDE_ESPERA_CADA_S', 5),

    // Instantanea. Los bytes son del texto de los archivos de datos (las fotos se cuentan aparte).
    'instantanea' => [
        'max_bytes_datos' => 4_000_000,     // total de los archivos de datos de un turno
        'max_bytes_archivo' => 1_200_000,   // un archivo de datos; si no cabe, se quitan filas del final (las mas antiguas) y se dice en LEEME.json
        'max_registros_comida' => 400,
        'max_feedback' => 500,
        'max_existencias' => 1000,
        'max_alimentos' => 1500,
        'menus_recientes' => 4,             // ademas del menu activo de cada hogar
        'dias_consumos' => 60,
        'historial_mensajes' => 20,         // ultimos mensajes de la conversacion (incluida la pregunta)
        'max_texto_mensaje' => 4000,        // caracteres de cada mensaje del historial
        'max_fotos' => 6,                   // fotos reales que se copian (las mas recientes primero)
        'max_bytes_fotos' => 10_000_000,    // suma de bytes de las fotos copiadas
    ],

    // Acciones validadas (AccionesChat).
    'acciones' => [
        'max_por_turno' => 10,
        'max_bytes_archivo' => 65536,
        'dias_atras' => 60,                 // no se registra ni corrige nada mas antiguo
        'max_descripcion' => 500,
        'caduca_min' => 30,                 // minutos que una accion destructiva espera la confirmacion de la persona
    ],
];
