# personal-nutrition


# Personal Nutrition: descripción del proyecto

**Idea general**
Un bot que planifica las comidas de cada día. Recibe información personal (gustos, historial, stock, etc.) y genera un menú semanal con recetas y tiempos de preparación.

**Entradas (IN)**
- **Feedback:** lo que me gusta y lo que no.
- **Historial:** lo que he comido, para no repetir y mantener variedad.
- **Stock:** lo que tengo disponible en casa.
- **Modelo:** qué es lo mejor, es decir, el criterio nutricional o de optimización que guía las decisiones.
- **Calendario:** lo que ya se ha sugerido anteriormente.
- **Tiempos:** cuánto tiempo hay para cocinar en el momento, para cada día de la semana.

**Salidas (OUT)**
Una lista de días (`Dia[]`). Cada día contiene:
- **Fecha.**
- **Comida / cena.**
- **Categorías de comida** (por ejemplo: carne roja, huevo, etc.).
- **Ejemplo de recetas**, cada una con:
  - Ingredientes.
  - Duración previa (preparación anticipada).
  - Duración al momento (cocinado en el momento).

**Flujo**
`IN → BOT → OUT`

---

# Cómo funciona

Es una aplicación Laravel 13 + Filament 5 (`admin/`) que orquesta un agente Claude Code aislado en otro contenedor (`agente/`). La arquitectura, las decisiones y las plantillas vienen de la guía `agentic-laravel/`.

```
navegador ──8000──▶ web ──┐                      ┌──────────────┐
                    queue ┼─ HTTP interno ──────▶│ agente       │ claude -p (skill planificar-menu)
                          │  (Bearer)            └──────┬───────┘
                          └───── S3 (garage) ◀──────────┘   encargo ▶  /  ◀ resultado
```

- **Laravel nunca ejecuta `claude`.** Le pide la ejecución al runner del agente por HTTP interno y lee su salida por *polling*.
- **Los ficheros viajan por S3** (Garage en desarrollo): el encargo hacia el agente y el resultado hacia Laravel. Los dos contenedores no comparten ningún volumen.
- **El agente no ve** la base de datos, el `.env` ni `APP_KEY`. Solo recibe el token de Claude, y solo durante una ejecución.
- **Cada respuesta del modelo se cuenta** (hook `Stop` + `consumos.py`) y Laravel guarda su coste con la tarifa de ese momento. Cambiar precios no reescribe el histórico.

## Qué hace el sistema

1. **Hogar:** tiempos de cocinado por día y por toma (comida, cena, y también desayuno, almuerzo y merienda; en estas tres, vacío = sin dato y no se limita), perfiles (cada uno con su modelo nutricional, restricciones, gustos y notas libres como el gimnasio), despensa (existencias, sin registro de compras).
2. **Seguimiento:** se registra lo que se comió *de verdad*. Lo planificado no cuenta como comido.
3. **Menús:** se elige la semana y se pulsa «Generar menú». El agente fija primero las categorías de alimento de cada comida y cena y luego propone 2-3 recetas dentro de ellas (todas comparten categorías). Además da una toma individual de desayuno, almuerzo y merienda por perfil y día, y una explicación por perfil. La administración valida la entrega (contrato versión 3, ver abajo) y la importa. El título del menú lleva el rango de fechas: «Nombre · del 2 al 8 de noviembre».
4. **PDF:** en la ficha del menú se elige la opción preferida de cada toma (por defecto, la primera) y «Descargar PDF» genera un documento. Orden: título con fechas, lista de la compra (comida y cena elegidas más todas las tomas individuales), «Cada persona» con su explicación y, por último, cada día con la comida y la cena elegidas y debajo las tomas individuales de cada persona. Se regenera cada vez, así que siempre refleja las elecciones actuales.

5. **Menú activo:** cada hogar tiene un menú activo (`hogares.menu_id`). El primer menú que se importa lo queda solo; después se cambia con «Activar» en la ficha. El listado de menús marca cuál es. Al activar otro se descartan los cambios del hogar.
6. **Editar con comentario:** en la ficha, «Editar con comentario» pide al agente que rehaga el menú entero siguiendo el texto («sin pescado el jueves»). Usa la skill `editar-menu` y la misma validación. No se guarda copia del menú anterior. Las elecciones se conservan solo si su opción sigue existiendo; los cambios del hogar se descartan. Si la edición falla, el menú sigue terminado con el motivo en `error`.
7. **Intercambio:** lo comido y el menú activo con sus cambios van al agente (skill `intercambiar-menu`), que puede mover platos entre tomas o sustituirlos dentro de las categorías de la toma. Devuelve `{"cambios": {"AAAA-MM-DD/comida": {"categorias": [...], "opciones": [...]}}}`, que se valida con las reglas de las opciones y se guarda en `hogares.cambios`; `menus.contenido` no se toca. Un intercambio nuevo se suma a los anteriores. Solo vale para el menú activo.
8. **Menú vigente y por defecto:** `Menu::contenidoVigente()` fusiona `contenido` con `hogares.cambios` (solo si el menú es el activo). La ficha, las elecciones y el PDF leen el vigente; el interruptor «Ver menú por defecto» muestra el original y «Descartar cambios» borra los cambios. Las tomas cambiadas llevan la marca «Cambiada».
9. **Lo comido:** el formulario pide hogar (si solo hay uno, se rellena y se oculta), uno o varios perfiles, fecha y momento. El campo de descripción sale deshabilitado con la receta programada (la opción elegida del menú activo, con los cambios). «Guardar» registra esa receta (`origen=menu`, `menu_id`, `opcion_ref`) y no llama al agente. «Otra cosa» vacía el campo para escribir lo comido (casa o fuera) y, al guardar, lanza el intercambio. Se crea un registro por perfil.
10. **Calendario:** página «Calendario» (grupo Seguimiento) con selector de hogar (solo si hay varios), de perfil y de vista (mes o semana). Cada día enseña la comida y la cena de ese perfil: lo comido de verdad (registro de «Lo comido»), lo programado en el menú activo con los cambios vigentes (de hoy en adelante) o lo programado que no se anotó (días pasados, con borde discontinuo). Si hay registro, manda sobre lo programado. Al pulsar un día se ve el detalle: opciones de la toma, desayuno, almuerzo y merienda propuestos y otras tomas anotadas. Los datos salen de `App\Filament\Support\DatosCalendario`.

Las tres tareas (generar, editar, intercambiar) comparten una sola infraestructura: el modelo `Menu` con la columna `tarea`, el mismo job, estado, registro y consumos.

### Contrato de entrega (versión 3)

Encargo y entrega llevan `version: 3`. El encargo añade `minutos_desayuno`, `minutos_almuerzo` y `minutos_merienda` a `hogar.tiempos`. La entrega lleva `dias[]` con `comida` y `cena` (`categorias` + `opciones`), `individuales[]` (una entrada por perfil con `desayuno`, `almuerzo` y `merienda`, una sola versión cada una) y, arriba, `explicaciones[]` (una por perfil). Cada opción de comida o cena puede llevar `ajustes[]` (`perfil_id` + `texto`, sin perfil repetido) para que cada persona adapte el mismo plato; las entregas v2 ya guardadas se siguen leyendo. La entrega de un intercambio es distinta: `entrega.version` 3 y `cambios` (ver arriba). Las reglas están en `admin/app/Models/Menu/ValidaEntrega.php` y en `agente/scripts/menu.py`: son las mismas y hay que cambiarlas a la vez. Detalle en `agente/.claude/skills/planificar-menu/SKILL.md`.

## Arranque

Requisitos: Docker con Compose.

```bash
openssl rand -hex 24                       # copia el resultado
printf 'AGENTE_SECRETO=<el valor>\n' > .env # fichero .env en la raíz (no se sube a git)
docker compose up -d --build               # construye, instala, migra y arranca
docker compose exec web php artisan db:seed --class=HogarSeeder   # opcional: hogar de ejemplo
docker compose exec web php artisan make:filament-user            # tu usuario del panel
```

El panel está en <http://localhost:8000/admin>. En Fedora u otros sistemas con SELinux el volumen del repositorio lleva la etiqueta `:z` (ya puesta en `docker-compose.yml`).

## Conectar Claude

El token **no va en el `.env`**: se pega en el panel y se guarda cifrado con `APP_KEY`.

1. En una máquina con Claude Code, ejecuta `claude setup-token` y copia el token.
2. En el panel: **Ajustes → Conexión con Claude → Pegar clave**.
3. Pulsa **Probar conexión**: debe responder «Claude responde».

Alternativa sin token: `docker compose exec -it agente claude login` (la sesión queda en el volumen `agente-home`).

Si cambias `APP_KEY`, el token guardado deja de poder descifrarse y hay que volver a pegarlo.

## Trabajar en el proyecto

| Si cambias… | Haz… |
|---|---|
| `agente/` (skill, scripts) o `docker/agente/` | `docker compose up -d --build agente`. El kit del agente viaja dentro de su imagen. |
| Servicios, modelos o jobs de `admin/` | `docker compose restart queue`. El worker no recarga código. |
| `AGENTE_SECRETO` | Recrear `web`, `queue` **y** `agente`. |
| Precios de los modelos | Editar `admin/config/agente.php` (`precios`). **Verifica las tarifas vigentes antes de fiarte del coste.** Solo afectan a lo que se registre desde ese momento. |

### Pruebas

```bash
docker compose exec web php artisan test                                  # PHP
docker run --rm -v "$PWD/agente":/w:z -w /w python:3.12-slim \
  python3 -I -B -m unittest discover -s tests                             # Python del agente
```

Para probar la cadena completa **sin gastar el plan** hay un «Claude falso» que habla el mismo protocolo y usa los scripts reales:

```bash
docker compose -f docker-compose.yml -f docker-compose.pruebas.yml up -d agente   # activar
docker compose exec agente sh -c 'echo lento > /app/agente/modo-falso'            # modos: ok, lento, limite, sinresultado, invalido
docker compose up -d agente                                                       # volver al Claude real
```

### Producción

Se elimina el servicio `garage`, se definen `AGENTE_S3_*` reales en el `.env` del servidor (con `AGENTE_S3_ENDPOINT=` vacío se usa AWS) y no se publica nunca el puerto 9000 del agente. Las claves de S3 por defecto del compose son solo de desarrollo.

## Estructura

```
admin/      Laravel + Filament (panel, modelos, cola, servicios del agente)
agente/     Kit del agente: CLAUDE.md, skills planificar-menu, editar-menu e intercambiar-menu, scripts (consumos.py, menu.py) y sus tests
docker/     Dockerfiles, runner HTTP del agente, Garage y el Claude falso (pruebas/)
agentic-laravel/   Guía de arquitectura origen (no se versiona)
```

