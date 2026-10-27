---
name: intercambiar-menu
description: Reajusta las tomas de comida y cena del menú activo de un hogar cuando lo comido no coincide con lo programado, y devuelve solo las tomas que cambian. Úsala solo en modo encargo, cuando el prompt diga «Realiza el trabajo N» y pida la skill intercambiar-menu.
---

# intercambiar-menu

Alguien ha comido algo distinto de lo programado (o va a hacerlo). Tu trabajo es reajustar el resto del menú para que la semana siga equilibrada sin tirar comida ni repetir. **Modo encargo: nadie puede responderte.** No hagas preguntas ni saludes.

El prompt dice `Realiza el trabajo N`. Todo está en `encargos/encargo-N.json`. Escribes solo en `workspace/`.

## Límites

Son los de la skill `planificar-menu` (restricciones y objetivos de cada perfil son límites; no eres profesional sanitario; no inventes datos; no calcules dinero; no toques `encargos/`, `base/` ni `scripts/`). Las reglas de contenido de cada toma (categorías, tiempos, ajustes) también valen aquí.

## Procedimiento

`N` es el número del encargo.

1. **Lectura.** `python scripts/consumos.py fase lectura --ejecucion N`. El encargo es el de `planificar-menu` y añade:
   - `tarea: "intercambiar"`.
   - `instruccion`: qué se comió y qué estaba programado, en texto libre.
   - `menu_activo`: lista de `{fecha, comida, cena}` con el menú **tal como vale ahora** (los cambios anteriores ya están aplicados). Cada toma tiene `categorias` y `opciones`.
   - `cambios_aplicados`: tomas que ya son un cambio anterior («2026-11-02/comida»).
   - `perfiles[].historial`: lo comido de verdad, que incluye lo que se acaba de registrar.
2. **Planificación.** `python scripts/consumos.py fase planificacion --ejecucion N`. Decide qué tomas cambian:
   - Lo ya comido no se cambia. Cambia solo tomas **futuras** respecto a lo que cuenta la `instruccion` y el `historial`.
   - Puedes **reubicar** un plato entre tomas (p. ej. lo que sobraba pasa a otra comida) y **sustituir** platos dentro de las categorías de la toma, o cambiar las categorías de la toma si lo comido las hace necesarias (no repetir la misma categoría principal el mismo día ni la misma semana).
   - Cambia lo mínimo: si una toma no necesita cambio, no la incluyas.
   - Solo comida y cena (no las tomas individuales) y solo donde el hogar cocina (`minutos_*` no nulo en `hogar.tiempos`).
   - Cada toma que incluyas es **completa**: `categorias` de la toma, entre `min` y `max` opciones, y cada opción con las mismas categorías que la toma, `titulo`, `ingredientes`, `duracion_previa_min`, `duracion_momento_min` (no más que el tiempo del hogar ese día) y `ajustes` opcionales (ver `planificar-menu`). No repitas títulos de la semana.
   Escribe el borrador en `workspace/borrador-N.json`.
3. **Validación.** `python scripts/consumos.py fase validacion --ejecucion N` y después
   `python scripts/menu.py validar workspace/borrador-N.json --encargo encargos/encargo-N.json`. Si da errores, corrige y repite.
4. **Entrega.** `python scripts/menu.py entregar workspace/borrador-N.json --encargo encargos/encargo-N.json`. Es lo último que haces; responde en una frase («Intercambio entregado»).

Si no consigues un borrador válido, **no entregues**. Responde explicando qué regla no pudiste cumplir y por qué.

## El borrador

No lleva `dias` ni `explicaciones`. Lleva `cambios`: un objeto cuya clave es `AAAA-MM-DD/comida` o `AAAA-MM-DD/cena` (una fecha de `semana.fechas`) y cuyo valor es la toma nueva. Puede ir vacío (`{}`) si no hace falta cambiar nada.

```json
{
  "entrega": {"version": 3},
  "cambios": {
    "2026-11-04/cena": {
      "categorias": ["pescado", "verdura"],
      "opciones": [
        {
          "titulo": "Merluza al horno con verduras",
          "categorias": ["pescado", "verdura"],
          "ingredientes": [{"nombre": "merluza", "cantidad": 600, "unidad": "g", "en_stock": false}],
          "duracion_previa_min": 5, "duracion_momento_min": 30,
          "pasos": ["…"]
        }
      ]
    }
  }
}
```

(En el ejemplo, la lista `opciones` lleva una sola opción por brevedad; en el borrador real van entre `min` y `max`.) No añadas `consumo` ni `entrega.completo`: lo pone `menu.py entregar`. Un intercambio **no modifica el menú**: la administración guarda estas tomas como cambios del hogar encima del menú activo.
