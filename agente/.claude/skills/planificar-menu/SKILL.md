---
name: planificar-menu
description: Genera el menú semanal de un hogar (comida y cena de cada día, 2-3 opciones de receta por comida, adaptadas a cada perfil) a partir de un encargo JSON. Úsala solo en modo encargo, cuando el prompt diga «Realiza el trabajo N» y pida la skill planificar-menu.
---

# planificar-menu

Planificas las comidas de un hogar para una semana. **Modo encargo: nadie puede responderte.** No hagas preguntas, no saludes, no ofrezcas menús. Si algo no está claro, decide con criterio y deja la decisión escrita en las adaptaciones.

El prompt dice `Realiza el trabajo N`. Todo lo que necesitas está en `encargos/encargo-N.json`. Escribes solo en `workspace/`.

## Límites que no se negocian

- **No eres un profesional sanitario.** Las restricciones y objetivos de cada perfil son **límites que respetas**, no diagnósticos que interpretas. No des consejo médico, no ajustes dosis, no afirmes que un plato «cura», «controla» o «previene» nada, y no cambies una restricción porque te parezca exagerada.
- Si no puedes cumplir la restricción de un perfil con una opción, **no propongas esa opción**. Nunca la propongas con una nota del tipo «cuidado».
- No inventes stock, tiempos ni gustos. Si el encargo no lo dice, no lo sabes.
- No calcules dinero ni tokens: de eso se encarga `scripts/consumos.py` y la administración.
- No toques `encargos/`, `base/` ni `scripts/`.

## Procedimiento

Cada paso marca una **fase** para el desglose de coste. `N` es el número del encargo.

1. **Lectura.** `python scripts/consumos.py fase lectura --ejecucion N`. Lee el encargo entero (formato abajo).
2. **Planificación.** `python scripts/consumos.py fase planificacion --ejecucion N`. Piensa el menú y escribe el borrador en `workspace/borrador-N.json` (formato abajo).
3. **Validación.** `python scripts/consumos.py fase validacion --ejecucion N`, y después:
   `python scripts/menu.py validar workspace/borrador-N.json --encargo encargos/encargo-N.json`.
   Si da errores, corrige el borrador y repite. La administración aplica **las mismas reglas**: un borrador que no valida aquí hace fallar el trabajo.
4. **Entrega.** `python scripts/menu.py entregar workspace/borrador-N.json --encargo encargos/encargo-N.json`.
   Vuelve a validar, añade el consumo y escribe el resultado en el campo `salida` del encargo. **Es lo último que haces**; después responde en una frase («Menú entregado»).

Si tras varios intentos no consigues un borrador válido, **no entregues**. Responde explicando qué regla no pudiste cumplir y por qué (la administración muestra tu último mensaje al usuario).

## El encargo

```json
{
  "formato": "encargo-menu", "version": 1, "encargo_id": 7, "salida": "workspace/resultado-7.json",
  "semana": {"inicio": "2026-11-02", "dias": 7, "fechas": ["2026-11-02", "…"]},
  "categorias_validas": ["carne_roja", "pescado", "…"],
  "opciones_por_comida": {"min": 2, "max": 3},
  "hogar": {"nombre": "Casa", "tiempos": [{"fecha": "2026-11-02", "dia_semana": 1, "minutos_comida": 30, "minutos_cena": 45}]},
  "perfiles": [{"id": 1, "nombre": "Marta", "objetivo": "…", "kcal_diarias": 1700,
                "macros_pct": {"proteina": 30, "carbohidratos": 40, "grasa": 30},
                "restricciones": ["sin lácteos"], "notas": null,
                "gusta": ["pescado azul"], "no_gusta": ["coliflor"],
                "historial": [{"fecha": "2026-10-30", "momento": "cena", "origen": "fuera", "descripcion": "…", "categorias": []}]}],
  "stock": [{"alimento": "arroz", "categoria": "cereal", "cantidad": 1000, "unidad": "g", "caduca_el": null}],
  "calendario_previo": [{"inicio": "2026-10-26", "dias": [{"fecha": "…", "comida": ["Lentejas"], "cena": []}]}]
}
```

- `hogar.tiempos`: minutos que el hogar tiene para cocinar **al momento** cada comida y cena. `null` = ese momento **no se cocina**.
- `perfiles[].historial` es lo que **se comió de verdad** (no lo que se planeó). `origen: fuera` significa que comió fuera de casa.
- `calendario_previo`: títulos de menús anteriores ya sugeridos (no son necesariamente lo que se comió).
- Las categorías van de `categorias_validas`; la primera de cada receta es la principal.

## Cómo planificar

**Una receta base por opción; se cocina una sola vez para todo el hogar.** Cada perfil la come adaptada (cantidades, sustituciones o un paso distinto al final). No hagas un plato distinto por persona.

1. **Tiempos.** `duracion_momento_min` ≤ los minutos del hogar de ese día y momento. Lo que se puede hacer antes (marinar, cocer legumbres, preparar una base) va en `duracion_previa_min` y no gasta el tiempo del momento. Si `minutos` es `null`, `opciones` va **vacío** (`[]`) ese momento.
2. **Opciones.** Entre `min` y `max` por comida y por cena. Que sean realmente distintas entre sí (otra categoría principal o otra técnica), no variantes del mismo plato.
3. **Restricciones y objetivos.** Mira cada perfil. Prefiere una receta base que ya cumpla las restricciones de todos. Si la base lleva algo que un perfil no puede tomar, la adaptación debe **quitarlo o sustituirlo de forma práctica** (p. ej. servir el queso aparte). Si no hay forma razonable, descarta la opción.
4. **Gustos.** Evita en la base lo que a cualquier perfil no le gusta; si aun así entra, esa persona lo omite en su adaptación. Favorece lo que les gusta.
5. **Variedad.** No repitas títulos en la semana, ni los de `calendario_previo`. Mira el `historial` de cada perfil (lo comido de verdad, sobre todo la última semana) y no repitas lo ya comido. Reparte las categorías principales a lo largo de la semana; no pongas la misma categoría principal en la comida y en la cena del mismo día.
6. **Stock.** Aprovecha lo que hay, empezando por lo que caduca antes. Marca `en_stock: true/false` en cada ingrediente según el encargo. Que el menú entero no dependa de comprar ingredientes raros.
7. **Adaptaciones.** Una por **cada** perfil del encargo (por `perfil_id`), siempre. Si no cambia nada, `cambios` es `"Sin cambios."`. Describe qué hace *esa persona* distinto (p. ej. «Marta: sin queso, ración de arroz más pequeña y doble de verdura. Javier: ración doble y un chorrito más de aceite»). `kcal_aprox` es opcional y es solo una estimación.
8. **Honestidad.** Estima los tiempos de forma realista. Si una duración es dudosa, sube el valor, no lo bajes para que quepa.

## El borrador (`workspace/borrador-N.json`)

Una entrada por cada fecha de `semana.fechas`, **en ese orden**.

```json
{
  "dias": [
    {
      "fecha": "2026-11-02",
      "comida": {"opciones": [
        {
          "titulo": "Arroz con pollo y verduras",
          "categorias": ["carne_blanca", "cereal", "verdura"],
          "ingredientes": [
            {"nombre": "arroz", "cantidad": 300, "unidad": "g", "en_stock": true},
            {"nombre": "pechuga de pollo", "cantidad": 500, "unidad": "g", "en_stock": true}
          ],
          "duracion_previa_min": 10,
          "duracion_momento_min": 25,
          "pasos": ["Cortar el pollo en dados…", "Sofreír…"],
          "adaptaciones": [
            {"perfil_id": 1, "cambios": "Sin queso. Ración de arroz más pequeña y doble de verdura.", "kcal_aprox": 480},
            {"perfil_id": 2, "cambios": "Ración doble y un chorrito más de aceite al servir.", "kcal_aprox": 900}
          ]
        }
      ]},
      "cena": {"opciones": []}
    }
  ]
}
```

- `ingredientes[].cantidad` es para **todo el hogar** junto. Cada ingrediente lleva `nombre`; `cantidad`, `unidad` y `en_stock` son muy recomendables.
- `duracion_previa_min` y `duracion_momento_min` son números **enteros** de minutos (0 o más).
- No añadas `consumo` ni `entrega`: lo pone `menu.py entregar`.
