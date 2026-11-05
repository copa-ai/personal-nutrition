---
name: planificar-menu
description: Genera el menú semanal de un hogar (comida y cena de cada día con 2-3 opciones de receta, más desayuno, almuerzo y merienda individuales por perfil, y una explicación por perfil) a partir de un encargo JSON. Úsala solo en modo encargo, cuando el prompt diga «Realiza el trabajo N» y pida la skill planificar-menu.
---

# planificar-menu

Planificas las comidas de un hogar para una semana. **Modo encargo: nadie puede responderte.** No hagas preguntas, no saludes, no ofrezcas menús. Si algo no está claro, decide con criterio y deja la decisión escrita en la explicación del perfil o en la `nota` de la toma.

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
2. **Planificación.** `python scripts/consumos.py fase planificacion --ejecucion N`. Razona el menú (primero las categorías de cada toma, luego las recetas; ver «Cómo planificar») y escribe el borrador en `workspace/borrador-N.json` (formato abajo).
3. **Validación.** `python scripts/consumos.py fase validacion --ejecucion N`, y después:
   `python scripts/menu.py validar workspace/borrador-N.json --encargo encargos/encargo-N.json`.
   Si da errores, corrige el borrador y repite. La administración aplica **las mismas reglas**: un borrador que no valida aquí hace fallar el trabajo.
4. **Entrega.** `python scripts/menu.py entregar workspace/borrador-N.json --encargo encargos/encargo-N.json`.
   Vuelve a validar, añade el consumo y escribe el resultado en el campo `salida` del encargo. **Es lo último que haces**; después responde en una frase («Menú entregado»).

Si tras varios intentos no consigues un borrador válido, **no entregues**. Responde explicando qué regla no pudiste cumplir y por qué (la administración muestra tu último mensaje al usuario).

## El encargo

```json
{
  "formato": "encargo-menu", "version": 3, "encargo_id": "0192f3c4-7a10-7b3e-9c1d-5e2f8a4b6d10", "salida": "workspace/resultado-0192f3c4-….json",
  "semana": {"inicio": "2026-11-02", "dias": 7, "fechas": ["2026-11-02", "…"]},
  "categorias_validas": ["carne_roja", "pescado", "…"],
  "opciones_por_comida": {"min": 2, "max": 3},
  "hogar": {"nombre": "Casa", "tiempos": [
    {"fecha": "2026-11-02", "dia_semana": 1, "minutos_comida": 30, "minutos_cena": 45,
     "minutos_desayuno": 10, "minutos_almuerzo": null, "minutos_merienda": 5}]},
  "perfiles": [{"id": "0192f3c4-8a11-7c20-b001-1a2b3c4d5e6f", "nombre": "Marta", "objetivo": "…", "kcal_diarias": 1700,
                "macros_pct": {"proteina": 30, "carbohidratos": 40, "grasa": 30},
                "restricciones": ["sin lácteos"], "notas": "Voy al gimnasio lunes y miércoles a las 19:00.",
                "gusta": ["pescado azul"], "no_gusta": ["coliflor"],
                "historial": [{"fecha": "2026-10-30", "momento": "cena", "origen": "fuera", "descripcion": "…", "categorias": []}]}],
  "stock": [{"alimento": "arroz", "categoria": "cereal", "cantidad": 1000, "unidad": "g", "caduca_el": null}],
  "calendario_previo": [{"inicio": "2026-10-26", "dias": [{"fecha": "…", "comida": ["Lentejas"], "cena": []}]}]
}
```

- `hogar.tiempos`: minutos que el hogar tiene para cocinar **al momento** cada toma ese día.
  - `minutos_comida` y `minutos_cena` (toma de todo el hogar): `null` = ese momento **no se cocina** (`categorias` y `opciones` van vacías).
  - `minutos_desayuno`, `minutos_almuerzo` y `minutos_merienda` (tomas individuales): `null` = **sin dato**, no hay límite que comprobar. Entonces propón algo sencillo o que se pueda dejar preparado el día antes.
- `perfiles[].id` es un UUID en texto: cópialo tal cual en cada `perfil_id` (no lo acortes ni lo cambies).
- `perfiles[].notas` es texto libre del hogar (horarios, gimnasio, trabajo…). **Interprétalo tú**: no hay campos aparte para eso.
- `perfiles[].historial` es lo que **se comió de verdad** (no lo que se planeó). `origen: fuera` significa que comió fuera de casa.
- `calendario_previo`: títulos de menús anteriores ya sugeridos (no son necesariamente lo que se comió).
- Las categorías van de `categorias_validas`.

## Cómo planificar

El menú tiene dos partes:

- **Comida y cena** (compartidas por todo el hogar): 2-3 opciones de receta cada una, cocinadas una sola vez para todos.
- **Desayuno, almuerzo y merienda** (individuales): una toma por perfil, **una sola versión** (sin opciones), todos los días, **fines de semana incluidos**.

Razona **en este orden**, de lo general a lo concreto:

1. **Categorías primero (comida y cena).** Para cada toma elige la lista de `categorias` de alimento (p. ej. `["legumbre", "verdura"]`) según el tiempo del hogar ese día, el `historial` de cada perfil (lo comido de verdad, sobre todo la última semana), la variedad de la semana y los objetivos. Reparte las categorías principales a lo largo de la semana y no repitas la misma categoría principal en la comida y la cena del mismo día. Si el hogar no cocina ese momento (`minutos` = `null`): `categorias: []` y `opciones: []`.
2. **Recetas dentro de esas categorías.** Ahora propón entre `min` y `max` recetas para la toma. **Todas las propuestas de una toma comparten las mismas categorías de alimento, acompañamientos incluidos**: el conjunto de `categorias` de cada opción (ignorando el orden) es igual al de la toma. Si el acompañamiento es un cereal, el cereal está en `categorias` de la toma y de **todas** sus opciones. Que las opciones sean distintas entre sí (otra técnica, otro plato), no variantes del mismo.
3. **Tiempos.** `duracion_momento_min` ≤ los minutos del hogar de ese día y momento, en cualquier toma. Lo que se puede hacer antes (marinar, cocer legumbres, preparar una base) va en `duracion_previa_min` y no gasta el tiempo del momento. Estima de forma realista; si dudas, sube el valor.
4. **Tomas individuales.** Una por perfil y día para `desayuno`, `almuerzo` y `merienda`, pensada para esa persona (objetivo, kcal, gustos, restricciones). Si hay `minutos_*`, cabe en ellos. Si es `null`, haz algo sencillo o déjalo preparado el día antes (`duracion_previa_min`). Usa `nota` para decirlo («Dejar la avena en remojo la noche anterior»).
5. **Comida pre y post entrenamiento.** Lee las `notas` de cada perfil: si indican ejercicio (gimnasio, running, clase…) en ciertos días y horas, adapta **ese día** la toma que cae antes (algo ligero, con hidratos y poca grasa) y la que cae después (con proteína). Hazlo solo si las notas lo dicen; no inventes entrenamientos. Cuéntalo en la explicación del perfil.
6. **Restricciones y objetivos.** Mira cada perfil. Las tomas individuales se hacen a medida de cada uno. En comida y cena, prefiere una receta que ya cumpla las restricciones de todos; si no hay forma razonable para algún perfil, descarta la opción (no hay adaptaciones por receta: el plato es el mismo para todos).
7. **Gustos.** Evita lo que a cualquier perfil no le gusta; favorece lo que le gusta.
8. **Variedad.** No repitas títulos en la semana ni los de `calendario_previo`, ni lo ya comido según el `historial`.
9. **Stock y cantidades.** Aprovecha lo que hay, empezando por lo que caduca antes. Cada ingrediente lleva `nombre`, `cantidad`, `unidad` y `en_stock` (según el encargo). En comida y cena la `cantidad` es para **todo el hogar** junto; en las tomas individuales, para **esa persona**. Un ingrediente genérico como «fruta» es válido y va tal cual a la lista de la compra: no hace falta concretar la pieza.
10. **Explicaciones.** Una por perfil en `explicaciones`, texto libre y breve que justifica el plan para esa persona, deducido de sus notas, objetivo e historial («Lunes y miércoles, que vas al gimnasio, el almuerzo es más ligero y la merienda lleva más proteína…»). Siempre una por cada perfil, aunque no haya nada especial que contar.
11. **Honestidad.** No inventes datos que el encargo no da.

## El borrador (`workspace/borrador-N.json`)

Lleva `entrega.version` = 3, una entrada en `dias` por cada fecha de `semana.fechas` **en ese orden**, y `explicaciones`. Cada día tiene `comida`, `cena` e `individuales` (una entrada por perfil, siempre).

```json
{
  "entrega": {"version": 3},
  "dias": [
    {
      "fecha": "2026-11-02",
      "comida": {
        "categorias": ["carne_blanca", "cereal", "verdura"],
        "opciones": [
          {
            "titulo": "Arroz con pollo y verduras",
            "categorias": ["cereal", "carne_blanca", "verdura"],
            "ingredientes": [
              {"nombre": "arroz", "cantidad": 300, "unidad": "g", "en_stock": true},
              {"nombre": "pechuga de pollo", "cantidad": 500, "unidad": "g", "en_stock": true}
            ],
            "duracion_previa_min": 10,
            "duracion_momento_min": 25,
            "pasos": ["Cortar el pollo en dados…", "Sofreír…"],
            "ajustes": [{"perfil_id": "0192f3c4-8a11-7c20-b001-1a2b3c4d5e6f", "texto": "Apartar el arroz antes de mezclar y añadir brócoli."}]
          }
        ]
      },
      "cena": {"categorias": [], "opciones": []},
      "individuales": [
        {
          "perfil_id": "0192f3c4-8a11-7c20-b001-1a2b3c4d5e6f",
          "desayuno": {
            "titulo": "Tostada integral con tomate",
            "descripcion": "Desayuno rápido sin lácteos.",
            "ingredientes": [{"nombre": "pan integral", "cantidad": 2, "unidad": "rebanadas", "en_stock": true}],
            "duracion_previa_min": 0, "duracion_momento_min": 5, "nota": null
          },
          "almuerzo": {
            "titulo": "Bocadillo ligero de pavo",
            "ingredientes": [{"nombre": "pavo en lonchas", "cantidad": 60, "unidad": "g", "en_stock": false}],
            "duracion_previa_min": 0, "duracion_momento_min": 5, "nota": "Ligero: hoy hay gimnasio por la tarde."
          },
          "merienda": {
            "titulo": "Fruta y frutos secos",
            "ingredientes": [{"nombre": "fruta", "cantidad": 1, "unidad": "pieza", "en_stock": false}],
            "duracion_previa_min": 0, "duracion_momento_min": 0
          }
        },
        {"perfil_id": "0192f3c4-8a12-7d31-b002-6f5e4d3c2b1a", "desayuno": {"…": "…"}, "almuerzo": {"…": "…"}, "merienda": {"…": "…"}}
      ]
    }
  ],
  "explicaciones": [
    {"perfil_id": "0192f3c4-8a11-7c20-b001-1a2b3c4d5e6f", "texto": "Lunes y miércoles, que vas al gimnasio, …"},
    {"perfil_id": "0192f3c4-8a12-7d31-b002-6f5e4d3c2b1a", "texto": "…"}
  ]
}
```

- `comida` y `cena` llevan `categorias` y `opciones`; cada opción lleva `categorias` con **el mismo conjunto** que la toma (el orden no importa).
- Cada toma individual lleva `titulo`, `ingredientes` (no vacío, cada uno con `nombre`), `duracion_previa_min` y `duracion_momento_min` (enteros ≥ 0). `descripcion` y `nota` son opcionales; `pasos` es opcional.
- `ajustes` (opcional, en cada opción de comida o cena): lista de `{perfil_id, texto}`, con un perfil del encargo y sin repetir perfil. Sirve para que cada persona adapte **el mismo plato** sin cocinar platos distintos (añadir brócoli, doble ración, apartar el arroz antes de mezclar). Dalos **solo si aportan**; si el plato ya sirve a todos tal cual, omite la clave. El texto no puede ir vacío.
- Los `ajustes` no cambian los ingredientes ni los tiempos de la opción: son indicaciones al cocinar. No hay recetas distintas por persona; la persona se refleja en los `ajustes`, en las tomas individuales y en `explicaciones`.
- No añadas `consumo` ni `entrega.completo`: lo pone `menu.py entregar`.
