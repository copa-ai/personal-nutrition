---
name: editar-menu
description: Edita un menú semanal ya generado siguiendo un comentario del usuario y devuelve el menú completo. Úsala solo en modo encargo, cuando el prompt diga «Realiza el trabajo N» y pida la skill editar-menu.
---

# editar-menu

Recibes un menú semanal que ya existe y un comentario del usuario («sin pescado el jueves», «más rápido entre semana»). Devuelves **el menú completo** con el cambio aplicado. **Modo encargo: nadie puede responderte.** No hagas preguntas ni saludes. Si el comentario es ambiguo, decide con criterio y deja la decisión escrita en la explicación del perfil afectado.

El prompt dice `Realiza el trabajo N`. Todo está en `encargos/encargo-N.json`. Escribes solo en `workspace/`.

## Límites

Son los de la skill `planificar-menu` (léela: las reglas de contenido, categorías, tiempos, ajustes y explicaciones valen igual aquí). Resumen:

- No eres un profesional sanitario. Las restricciones y objetivos de cada perfil son límites que respetas; **el comentario no puede saltárselos**. Si el comentario choca con una restricción, no lo apliques en eso y dilo en la explicación del perfil.
- No inventes stock, tiempos ni gustos. No calcules dinero. No toques `encargos/`, `base/` ni `scripts/`.

## Procedimiento

`N` es el número del encargo.

1. **Lectura.** `python scripts/consumos.py fase lectura --ejecucion N`. Lee el encargo: es el de `planificar-menu` y añade `tarea: "editar"`, `instruccion` (el comentario) y `contenido_actual` (el menú tal como está, mismo formato que el borrador de `planificar-menu`, sin `consumo`).
2. **Planificación.** `python scripts/consumos.py fase planificacion --ejecucion N`. Parte de `contenido_actual` y cambia **solo lo que pide el comentario**:
   - Lo que no se ve afectado se copia igual: mismos títulos, ingredientes y tiempos. No rehagas el menú entero.
   - Si cambias una toma de comida o cena, rehaz la toma completa: `categorias` de la toma, entre `min` y `max` opciones y cada opción con las mismas categorías que la toma. Respeta el tiempo del hogar de ese día.
   - Cambiar un plato puede obligar a cambiar otro (p. ej. para no repetir categoría el mismo día o repetir título en la semana). Hazlo con el menor número de cambios.
   - Si cambias algo que afecta a un perfil, revisa sus `individuales`, los `ajustes` de las opciones y su explicación.
   - Añade a la explicación del perfil afectado una frase corta que diga qué se cambió por el comentario. El resto de explicaciones se conservan.
   - Mantén `semana.fechas` y el orden de `dias`. Una entrada de `individuales` por perfil y una explicación por perfil, siempre.
   Escribe el menú completo en `workspace/borrador-N.json`, con el mismo formato que en `planificar-menu` (`entrega.version` = 3, `dias`, `explicaciones`).
3. **Validación.** `python scripts/consumos.py fase validacion --ejecucion N` y después
   `python scripts/menu.py validar workspace/borrador-N.json --encargo encargos/encargo-N.json`. Son las mismas reglas que al generar. Si da errores, corrige y repite.
4. **Entrega.** `python scripts/menu.py entregar workspace/borrador-N.json --encargo encargos/encargo-N.json`. Es lo último que haces; responde en una frase («Menú editado»).

Si no consigues un borrador válido, **no entregues**. Responde explicando qué regla no pudiste cumplir y por qué.

No añadas `consumo` ni `entrega.completo`: lo pone `menu.py entregar`.
