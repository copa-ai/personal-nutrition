<?php

/*
 * Chat de la instancia (interfaz «chat» de chatui, chat.md revision 6). El traductor gRPC de docker/chat/ habla con Laravel por
 * HTTP interno (ver docs/chat-api-interna.md). Los limites son los de chat.md §7.
 */
return [
    // Bearer que el traductor presenta en /interno/chat/*. Sin valor, ninguna llamada pasa.
    'secreto' => env('CHAT_SECRETO'),

    'tipos_mime' => ['image/jpeg', 'image/png'],
    'max_texto_bytes' => 16384,
    'max_peticion_bytes' => 131072,
    'max_archivos' => 4,
    'max_archivo_bytes' => 4194304,
    'max_total_archivos_bytes' => 536870912,   // 512 MiB de fotos en total (chat_archivos); mas alla, storage_full
    'max_historial' => 10000,       // mensajes en total (usuario + servicio); se rechaza el envio que no deje sitio para dos
    'caducidad_respuesta_min' => 60, // una respuesta abierta desde hace mas se da por interrumpida (FAILED)
    'max_en_espera' => 8,           // respuestas abiertas (PENDING o IN_PROGRESS) a la vez
    'max_delta_bytes' => 65536,
    'max_mensaje_bytes' => 1000000, // un mensaje serializado debe caber en 1 MiB
    'limite_lectura' => 500,
    'max_bytes_lectura' => 8388608, // una lectura o sincronizacion no devuelve mas de ~8 MiB de texto (siempre al menos un mensaje)
    'max_abiertos_sincronizar' => 100,
    'max_secreto_bytes' => 8192,
    'max_sugerencias' => 3,
    'titulo_palabras' => 6,

    // Motores de respuesta que enlaza RespondedorMixto (los escriben otros; si la clase no existe se usa el eco).
    'respondedor_claude' => 'App\\Services\\Chat\\Claude\\RespondedorClaude',
    'respondedor_bot' => 'App\\Services\\Chat\\Bot\\RespondedorBot',
];
