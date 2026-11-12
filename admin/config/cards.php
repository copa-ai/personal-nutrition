<?php

/*
 * Lista de la compra con la interfaz «cards» de chatui (cards.md, revision 1). El traductor gRPC de docker/chat/ (segundo puerto del
 * mismo proceso) habla con Laravel por HTTP interno bajo /interno/cards, con el mismo Bearer que el chat (ver docs/chat-api-interna.md).
 * Los limites de tarjetas y textos son los de cards.md §8.
 */
return [
    // Puerto del slot «cards» en service.json -> api. Es el que lleva el slot_link del chat (chat.md §5.8): el cliente lo busca entre los slots.
    'puerto_slot' => (int) env('CARDS_PUERTO', 50052),

    'max_tarjetas' => 500,
    'max_titulo_bytes' => 256,
    'max_texto_bytes' => 1024,
    'max_detalle_bytes' => 16384,
    'max_grupo_bytes' => 64,
    'max_bytes_mazo' => 700000,          // un DeckEvent no pasa de 1 MiB: si el mazo no cabe, se acortan los detalles (nunca se parte)

    'max_eventos_leidos' => 200,         // por sondeo; el traductor vuelve a pedir el mazo entero si se queda muy atras
    'eventos_conservados' => 500,
    'dias_decisiones' => 30,             // las decisiones y claves de mazos antiguos se borran pasado este tiempo
    // Segundos de inactividad tras los que el mazo ya no se reanuda al reconectar: se genera uno nuevo con el menu y la despensa de ese momento.
    'reanudar_s' => 300,

    // Ilustraciones: las dibuja el agente de Claude (SVG de un subconjunto cerrado) y se guardan como PNG en el SQLite del chat.
    'ilustraciones' => [
        'habilitadas' => (bool) env('CARDS_ILUSTRACIONES', true),
        'max_por_lote' => 12,            // alimentos por encargo al agente
        'max_lotes_dia' => 6,            // tope de coste: encargos al agente en 24 h
        'max_intentos' => 3,             // por alimento; pasados, se queda con la ilustracion de su categoria
        'espera_reintento_min' => 60,    // entre un intento fallido y el siguiente del mismo alimento
        'timeout' => 600,                // segundos de un encargo
        'herramientas' => env('CARDS_HERRAMIENTAS', 'Read'),
        'max_svg_bytes' => 6144,         // un SVG del agente; mas grande se descarta
        'max_formas' => 60,              // elementos de dibujo por SVG
        'lado_png' => 256,               // PNG cuadrado resultante, en pixeles
        'max_png_bytes' => 262144,
        'espera_agente_cada_s' => 5,
        'espera_agente_max_s' => 180,
    ],
];
