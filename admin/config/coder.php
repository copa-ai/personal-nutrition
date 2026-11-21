<?php

/*
 * The Coder: a Celaut service that nutrition starts as a child to change the shared code. See docs/coder/ and App\Services\Coder.
 * All values are constants. The user changes only the settings that the Coder panel stores with App\Models\Ajuste.
 */
return [
    // Directories. `directorio` holds the control files of the reload watcher and of the SSH forwarder (docker/servicio/start.sh).
    'directorio' => '/var/lib/nutrition/coder',
    'secretos' => '/var/lib/nutrition/secretos/coder',   // the SSH host key. start.sh makes it, owner app, mode 700
    'proyecto' => '/opt/nutrition/project',              // the shared directory (service.json shared_filesystems)

    // The Coder must declare a guest share with this tag. It must be the tag of the share in .service/service.json.
    'tag_compartido' => 'project',
    'usuario_ssh' => 'coder',
    'puerto_ssh' => 2222,            // static api slot of nutrition (service.json) and port of docker/servicio/ssh-proxy.py
    'variable_clave_host' => 'CODER_SSH_HOST_KEY',

    // A service package that the user uploads (.celaut.bee).
    'paquete' => [
        'subcarpeta' => 'paquetes',  // inside `directorio`
        'extension' => '.celaut.bee',
        'tamano_maximo_mb' => 512,
    ],

    'claves' => [
        'maximo' => 20,              // SSH public keys in .coder/authorized_keys
        'comentario_maximo' => 64,   // characters of the comment of a key
    ],

    // Launch. A start can take minutes: the node builds and starts the service before it answers.
    'lanzamiento' => [
        'espera_ssh_s' => 180,       // time to wait for the SSH banner of the new Coder
        'pausa_ssh_s' => 5,
        'conexion_ssh_s' => 5,       // time limit of one connection test
        'colgado_s' => 2400,         // a state "launching" older than this is an error (the job died)
        'inicial_mu' => null,        // --initial-mu of the start. null: the node uses the cost plus its default balance (about 1 hour)
    ],

    // Admission bound that service.json declares (possible_environment_workload). The panel shows it. The node does not tell the real resources.
    'recursos' => [
        'memoria_gib' => 3,
        'disco_gib' => 10,
        'cpus' => 2,
    ],

    // The celaut CLI (v0.6.3) cannot read the spec of a service. With this value, a launch whose spec we cannot read is refused.
    // Value "confirmar": the user can confirm in the panel that the service mounts the share.
    'spec_desconocida' => 'rechazar',
];
