<?php

namespace App\Services\Chat\Marcado;

use InvalidArgumentException;

/** Marcado que incumple chat.md §5.4 o §5.5. El mensaje dice qué regla y está pensado para leerlo (también un modelo que corrige). */
class MarcadoInvalido extends InvalidArgumentException {}
