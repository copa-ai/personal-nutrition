<?php

namespace App\Services\Chat\Claude\Acciones;

/**
 * Un grupo de acciones que el agente del chat puede proponer. AccionesChat reparte cada accion en el grupo que declara su tipo.
 *
 * La accion normalizada que devuelve validar() lleva siempre `tipo` y `quiero` (lo que se va a hacer, en una frase para la persona,
 * en presente: «Borrar el perfil Marta»). Los grupos no tocan la base al validar.
 */
interface GrupoAcciones
{
    /**
     * Tipos que atiende el grupo y las claves que admite cada uno (siempre incluida «tipo»). Una clave de mas se rechaza.
     *
     * @return array<string, list<string>>
     */
    public function claves(): array;

    /**
     * Valida una accion del tipo dado (ya comprobado que es del grupo y sin claves de mas).
     *
     * @param  array<string, mixed>  $accion
     * @return array<string, mixed>|string la accion normalizada, o el motivo del rechazo
     */
    public function validar(string $tipo, array $accion): array|string;

    /**
     * Si la accion (ya normalizada) pide confirmacion de la persona antes de ejecutarse: borrar, descartar, sustituir o rehacer.
     *
     * @param  array<string, mixed>  $normalizada
     */
    public function destructiva(array $normalizada): bool;

    /**
     * Ejecuta la accion normalizada (dentro de la transaccion de AccionesChat).
     *
     * @param  array<string, mixed>  $normalizada
     * @return string lo que se hizo, para la persona
     *
     * @throws \InvalidArgumentException si en este momento no se puede (la accion se rechaza con ese mensaje y el resto sigue)
     */
    public function aplicar(array $normalizada): string;
}
