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

    // Funding controls (App\Services\Coder\Saldo). The user changes the three limits in the panel (settings coder.tope_mu,
    // coder.porcentaje_max, coder.horas_minimas). The values here are the defaults and the constants of the rules.
    'saldo' => [
        'horas_tope' => 24,          // default total cap: this many hours of Coder maintenance at the current price
        'margen' => 1.25,            // margin on the default cap, for a price rise
        'porcentaje' => 20,          // default: the Coder holds at most this percentage of the joint balance
        'horas_minimas' => 48,       // default: nutrition keeps at least this many hours of life after a transfer
        'objetivo_horas' => 12,      // the watcher tops the Coder up to this many hours of maintenance
        'umbral' => 0.5,             // the watcher tops up only when the balance is under this part of the target
        'precio_vigente_s' => 600,   // a read of celaut cost stays valid for this time
        'muestras_minimas' => 4,     // fewer samples: the burn rate is unknown and nothing is sent
        'segundos_minimos' => 600,   // the samples must span at least this time
        'muestras_maximo' => 72,     // samples kept (6 hours at one per 300 s)
        'muestras_vigencia_s' => 43200,
    ],
];
