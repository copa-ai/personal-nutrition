<?php

/*
 * Mensajes de validación en español. Solo las reglas de uso habitual: una regla que no esté aquí cae al inglés de Laravel.
 * Los nombres de campo los pone cada formulario (Filament pasa su etiqueta), así que no hay lista de `attributes`.
 */
return [
    'accepted' => 'Debes aceptar :attribute.',
    'array' => ':Attribute debe ser una lista.',
    'before' => ':Attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':Attribute debe ser una fecha anterior o igual a :date.',
    'after' => ':Attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':Attribute debe ser una fecha posterior o igual a :date.',
    'between' => [
        'array' => ':Attribute debe tener entre :min y :max elementos.',
        'numeric' => ':Attribute debe estar entre :min y :max.',
        'string' => ':Attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':Attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'date' => ':Attribute no es una fecha válida.',
    'date_format' => ':Attribute no tiene el formato :format.',
    'decimal' => ':Attribute debe tener :decimal decimales.',
    'different' => ':Attribute y :other deben ser distintos.',
    'email' => ':Attribute debe ser un correo electrónico válido.',
    'exists' => ':Attribute no es válido.',
    'filled' => ':Attribute no puede estar vacío.',
    'gt' => [
        'numeric' => ':Attribute debe ser mayor que :value.',
        'string' => ':Attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'numeric' => ':Attribute debe ser mayor o igual que :value.',
        'string' => ':Attribute debe tener :value caracteres o más.',
    ],
    'integer' => ':Attribute debe ser un número entero.',
    'in' => ':Attribute no es válido.',
    'json' => ':Attribute debe ser un JSON válido.',
    'lt' => [
        'numeric' => ':Attribute debe ser menor que :value.',
        'string' => ':Attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'numeric' => ':Attribute debe ser menor o igual que :value.',
        'string' => ':Attribute debe tener :value caracteres o menos.',
    ],
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'array' => ':Attribute debe tener al menos :min elementos.',
        'numeric' => ':Attribute debe ser al menos :min.',
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'not_in' => ':Attribute no es válido.',
    'numeric' => ':Attribute debe ser un número.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':Attribute es obligatorio.',
    'required_if' => ':Attribute es obligatorio cuando :other es :value.',
    'required_with' => ':Attribute es obligatorio cuando :values está presente.',
    'same' => ':Attribute y :other deben coincidir.',
    'size' => [
        'array' => ':Attribute debe tener :size elementos.',
        'numeric' => ':Attribute debe ser :size.',
        'string' => ':Attribute debe tener :size caracteres.',
    ],
    'string' => ':Attribute debe ser un texto.',
    'unique' => ':Attribute ya está en uso.',
    'url' => ':Attribute debe ser una URL válida.',
    'uuid' => ':Attribute debe ser un UUID válido.',
    'custom' => [],
    'attributes' => [],
];
