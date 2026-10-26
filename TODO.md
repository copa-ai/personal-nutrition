# Pendiente

Lo que queda abierto tras el rediseño de la ficha del menú. Ordenado por importancia.

## Calidad de lo que genera el agente

- [ ] **Las adaptaciones por persona salen idénticas en todas las opciones.** En el menú real nº 2 el texto de Marta y de Javier es el mismo en las 28 opciones («Sin lácteos (la receta no los lleva)…, sin coliflor…»), incluso cuando el plato no lleva coliflor. La skill pide adaptaciones concretas al plato, pero el modelo copia una plantilla.
  - Endurecer `agente/.claude/skills/planificar-menu/SKILL.md`: cada `cambios` debe nombrar un ingrediente o una cantidad *de ese plato*.
  - Valorar una regla en `ValidaEntrega` y en `scripts/menu.py` (las dos a la vez) que rechace un `cambios` repetido literalmente en más de N opciones.
- [ ] **Tarifas sin verificar.** Los precios de `admin/config/agente.php` son los de la guía. Faltan los modelos 5.5 de Haiku y Fable. Hasta revisarlos, el coste en dólares es orientativo.

## Interfaz

- [ ] **Modo oscuro sin verificar.** Los colores usan variables de Filament y `color-mix`, así que deberían adaptarse, pero el navegador sin interfaz que usé para las capturas no aplicó el esquema oscuro. Mirarlo a mano.
- [ ] **Elegir opción en un navegador real.** La interacción (botones de radio de cada toma) está probada con Livewire, pero no con clics reales. Comprobar también el foco con teclado: ahora cada opción es un botón con `Tab`; las flechas no cambian de opción.
- [ ] **Resto de pantallas sin el mismo repaso:** Hogares, Perfiles, Existencias, Compras, Alimentos y Lo comido. La lista de menús se vio antes de poner la interfaz en español.
- [ ] **Zona horaria.** `config/app.php` sigue en `UTC`, así que el registro del agente muestra horas UTC. Valorar `APP_TIMEZONE=Europe/Madrid`.
- [ ] **Convenciones del equipo (`tall-template`)** no aplicadas: tema (Neobrutalism) y plugins. La skill no era legible desde el sandbox. La interfaz usa estilos propios (`resources/views/filament/menus/_estilos.blade.php`) porque no hay tema compilado.

## PDF

- [ ] Quedan huecos al final de algunas páginas: una fila de día no se parte entre páginas.
- [ ] Tipografía DejaVu en vez de Instrument Serif y Karla (dompdf no admite fuentes web). Para igualarla habría que guardar los `.ttf` (licencia OFL) en el repo y registrarlos en dompdf.
- [ ] Secciones de la plantilla `catorce-platos` que no se han trasladado por falta de datos: «Lunes por la tarde», «La nevera pequeña», notas y «El reparto».

## Proyecto

- [ ] **Primer commit.** Decidir qué hacer con `admin/.claude/` (skills de Laravel Boost), `admin/public/` (assets de Filament que regenera `preparar`) y `.claude/` de la raíz (nodos especiales del sandbox; `git add .` falla con él). `catorce-platos/` ya está en `.gitignore` y `.dockerignore`.
- [ ] Borrar `catorce-platos/` cuando ya no haga falta (está fuera de git).
- [ ] El `README.md` menciona el PDF pero no la pestaña Compra ni el cambio de idioma a español (`APP_LOCALE=es`).
