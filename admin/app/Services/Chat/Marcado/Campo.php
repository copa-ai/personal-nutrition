<?php

namespace App\Services\Chat\Marcado;

/** Un campo de un bloque keyboard (chat.md §5.5): texto, date, time, range, checkbox o select. Se crea con los métodos estáticos. */
final class Campo
{
    private function __construct(public readonly string $nombre, private readonly Nodo $nodo) {}

    /** @throws MarcadoInvalido */
    private static function nombre(string $nombre): string
    {
        if (preg_match(Reglas::PATRON_NOMBRE, $nombre) !== 1) {
            throw new MarcadoInvalido("El data-name «{$nombre}» no es válido (1 a 32 caracteres de A-Z, a-z, 0-9, _ y -).");
        }

        return $nombre;
    }

    /** @return array<string, string|true> */
    private static function base(string $nombre, ?string $tipo, string $ayuda): array
    {
        $a = $tipo === null ? [] : ['type' => $tipo];
        $a['data-name'] = self::nombre($nombre);
        if (Reglas::linea($ayuda) !== '') {
            $a['placeholder'] = Reglas::cortar(Reglas::linea($ayuda), Reglas::MAX_PLACEHOLDER);
        }

        return $a;
    }

    private static function valor(string $texto, string $que): string
    {
        $texto = Reglas::linea($texto);
        if ($texto === '' || mb_strlen($texto) > Reglas::MAX_VALOR) {
            throw new MarcadoInvalido("{$que} debe tener de 1 a ".Reglas::MAX_VALOR.' caracteres.');
        }

        return $texto;
    }

    /** @param  string  $ayuda  el placeholder: la pista del campo (máximo 80 caracteres) */
    public static function texto(string $nombre, string $ayuda = '', ?string $valor = null): self
    {
        $a = self::base($nombre, null, $ayuda);
        if ($valor !== null && Reglas::linea($valor) !== '') {
            $a['value'] = self::valor($valor, 'El valor inicial');
        }

        return new self($nombre, Nodo::elemento('input', $a));
    }

    /** @param  string|null  $valor  día inicial YYYY-MM-DD; $min y $max, también */
    public static function fecha(string $nombre, string $ayuda = '', ?string $valor = null, ?string $min = null, ?string $max = null): self
    {
        $a = self::base($nombre, 'date', $ayuda);
        foreach (['min' => $min, 'max' => $max, 'value' => $valor] as $clave => $dia) {
            if ($dia === null) {
                continue;
            }
            if (! Reglas::fechaReal($dia)) {
                throw new MarcadoInvalido("«{$dia}» no es un día real (YYYY-MM-DD).");
            }
            $a[$clave] = $dia;
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new MarcadoInvalido('El mínimo de la fecha es posterior al máximo.');
        }

        return new self($nombre, Nodo::elemento('input', $a));
    }

    public static function hora(string $nombre, string $ayuda = '', ?string $valor = null): self
    {
        $a = self::base($nombre, 'time', $ayuda);
        if ($valor !== null) {
            if (! Reglas::horaReal($valor)) {
                throw new MarcadoInvalido("«{$valor}» no es una hora HH:MM de 00:00 a 23:59.");
            }
            $a['value'] = $valor;
        }

        return new self($nombre, Nodo::elemento('input', $a));
    }

    public static function rango(string $nombre, string $ayuda = '', float|int $min = 0, float|int $max = 100, float|int $paso = 1, float|int|null $valor = null): self
    {
        if ($max <= $min || $paso <= 0 || ! is_finite((float) $min) || ! is_finite((float) $max) || ! is_finite((float) $paso)) {
            throw new MarcadoInvalido('Un rango necesita mín < máx y un paso mayor que 0.');
        }
        $a = self::base($nombre, 'range', $ayuda);
        $texto = fn (float|int $n): string => rtrim(rtrim(number_format((float) $n, 6, '.', ''), '0'), '.');
        $a['min'] = $texto($min);
        $a['max'] = $texto($max);
        $a['step'] = $texto($paso);
        if ($valor !== null) {
            $a['value'] = $texto($valor);
        }

        return new self($nombre, Nodo::elemento('input', $a));
    }

    public static function casilla(string $nombre, string $ayuda = '', string $siMarcada = 'yes', string $siNo = 'no', bool $marcada = false): self
    {
        $a = self::base($nombre, 'checkbox', $ayuda);
        $a['value'] = self::valor($siMarcada, 'El valor de la casilla marcada');
        $a['data-off'] = self::valor($siNo, 'El valor de la casilla sin marcar');
        if ($marcada) {
            $a['checked'] = true;
        }

        return new self($nombre, Nodo::elemento('input', $a));
    }

    /**
     * @param  array<int|string, string>  $opciones  valor => etiqueta (valores distintos, de 1 a 200 caracteres; hasta 32 opciones)
     * @param  list<string>  $elegidas  valores elegidos al principio (sin $multiple solo cuenta el primero)
     */
    public static function seleccion(string $nombre, array $opciones, string $ayuda = '', bool $multiple = false, array $elegidas = []): self
    {
        if ($opciones === [] || count($opciones) > Reglas::MAX_OPCIONES) {
            throw new MarcadoInvalido('Un select tiene de 1 a '.Reglas::MAX_OPCIONES.' opciones.');
        }
        $a = self::base($nombre, null, $ayuda);
        if ($multiple) {
            $a['multiple'] = true;
        }
        $elegidas = array_map('strval', $elegidas);
        $hijos = [];
        $hayElegida = false;
        foreach ($opciones as $valor => $etiqueta) {
            $valor = self::valor((string) $valor, 'El valor de una opción');
            $atributos = ['value' => $valor];
            if (in_array($valor, $elegidas, true) && ($multiple || ! $hayElegida)) {
                $atributos['selected'] = true;
                $hayElegida = true;
            }
            $texto = Reglas::cortar(Reglas::linea((string) $etiqueta) !== '' ? Reglas::linea((string) $etiqueta) : $valor, Reglas::MAX_ETIQUETA_OPCION);
            $hijos[$valor] = Nodo::elemento('option', $atributos, [$texto]);
        }
        if (count($hijos) !== count($opciones)) {
            throw new MarcadoInvalido('Dos opciones de un select tienen el mismo valor.');
        }

        return new self($nombre, Nodo::elemento('select', $a, array_values($hijos)));
    }

    public function nodo(): Nodo
    {
        return $this->nodo;
    }
}
