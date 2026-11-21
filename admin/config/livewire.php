<?php

/*
 * Livewire settings that this app changes. Livewire reads every other value from its own defaults.
 * The Coder page uploads a service package (.celaut.bee). The limit is the same as config coder.paquete.tamano_maximo_mb.
 * The PHP limits are in .service/Dockerfile (upload_max_filesize, post_max_size). The Caddy limit is in docker/servicio/Caddyfile.
 */
return [
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:'.(512 * 1024)],   // kilobytes
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [],
        'max_upload_time' => 15,   // minutes
        'cleanup' => true,
    ],
];
