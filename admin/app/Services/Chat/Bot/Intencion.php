<?php

namespace App\Services\Chat\Bot;

/** Lo que el bot ha entendido de un mensaje: un tipo y los datos que ya se pudieron sacar. */
final class Intencion
{
    public const SALUDO = 'saludo';

    public const GRACIAS = 'gracias';

    public const ADIOS = 'adios';

    public const AYUDA = 'ayuda';

    public const MENU = 'menu';

    public const SEMANA = 'semana';

    public const DESPENSA = 'despensa';

    /** Quiere ver la lista de la compra (el mazo de cards). */
    public const COMPRA = 'compra';

    /** Una orden «registrar comida perfil=… momento=… fecha=…: …» (la de la tecla del formulario). */
    public const REGISTRAR_ORDEN = 'registrar_orden';

    /** Quiere registrar algo, con o sin datos (texto libre, o una foto). */
    public const REGISTRAR = 'registrar';

    /** Pide algo que requiere IA (análisis, recomendaciones, recetas...). */
    public const IA = 'ia';

    public const NO_ENTIENDO = 'no_entiendo';

    /** @param  array<string, mixed>  $datos */
    public function __construct(public readonly string $tipo, public readonly array $datos = []) {}
}
