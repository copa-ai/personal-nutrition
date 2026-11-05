# Pendiente

Lo que queda abierto tras el rediseño de la ficha del menú. Ordenado por importancia.

## Calidad de lo que genera el agente

- [ ] **Probar el contrato v2 con el modelo real.** Las reglas están probadas con entregas escritas a mano y con `claude-falso.py`, pero no con una ejecución real. Mirar si el agente respeta que todas las opciones de una toma compartan categorías y si las explicaciones salen concretas (gimnasio, horarios) y no una plantilla repetida.
- [ ] **Probar el contrato v3 y las skills nuevas con el modelo real.** `ajustes` por opción (que solo salgan cuando aportan, no en todas), `editar-menu` (devuelve el menú entero y toca solo lo pedido) e `intercambiar-menu` (reubica platos y respeta las categorías de la toma). Hoy solo se han probado con `claude-falso.py` y entregas escritas a mano.
- [ ] **Ejecutar `php artisan test` con los tests nuevos.** Se escribieron `EdicionIntercambioTest`, `InterfazEdicionTest` y casos nuevos en `EntregaMenuTest`, `EjecutorAgenteTest` y `MenuPdfTest` sin PHP ni Docker en el sandbox: no se han ejecutado. Ejecutar también `migrate:fresh` con las migraciones de `menu_id`, `cambios`, `tarea`, `instruccion` y `contenido_anterior`.
- [ ] **Ejecutar `php artisan test` y `migrate:fresh` tras la migración a v2.** En la última revisión el sandbox no tenía PHP ni acceso a Docker: solo se pasaron los tests de Python.
- [ ] **Tarifas sin verificar.** Los precios de `admin/config/agente.php` son los de la guía. Faltan los modelos 5.5 de Haiku y Fable. Hasta revisarlos, el coste en dólares es orientativo.

## Interfaz

- [ ] **Modo oscuro sin verificar.** Los colores usan variables de Filament y `color-mix`, así que deberían adaptarse, pero el navegador sin interfaz que usé para las capturas no aplicó el esquema oscuro. Mirarlo a mano.
- [ ] **Elegir opción en un navegador real.** La interacción (botones de radio de cada toma) está probada con Livewire, pero no con clics reales. Comprobar también el foco con teclado: ahora cada opción es un botón con `Tab`; las flechas no cambian de opción.
- [ ] **Resto de pantallas sin el mismo repaso:** Hogares, Perfiles, Existencias, Alimentos y Lo comido. La lista de menús se vio antes de poner la interfaz en español.
- [ ] **Zona horaria.** `config/app.php` sigue en `UTC`, así que el registro del agente muestra horas UTC. Valorar `APP_TIMEZONE=Europe/Madrid`.
- [ ] **Convenciones del equipo (`tall-template`)** no aplicadas: tema (Neobrutalism) y plugins. La skill no era legible desde el sandbox. La interfaz usa estilos propios (`resources/views/filament/menus/_estilos.blade.php`) porque no hay tema compilado.

## PDF

- [ ] Quedan huecos al final de algunas páginas: una fila de día no se parte entre páginas. Con las tomas individuales cada día es más alto: revisar el PDF real.
- [ ] Tipografía DejaVu en vez de Instrument Serif y Karla (dompdf no admite fuentes web). Para igualarla habría que guardar los `.ttf` (licencia OFL) en el repo y registrarlos en dompdf.
- [ ] Secciones de la plantilla `catorce-platos` que no se han trasladado por falta de datos: «Lunes por la tarde», «La nevera pequeña», notas y «El reparto».

## Proyecto

- [ ] **Primer commit.** Decidir qué hacer con `admin/.claude/` (skills de Laravel Boost), `admin/public/` (assets de Filament que regenera `preparar`) y `.claude/` de la raíz (nodos especiales del sandbox; `git add .` falla con él). `catorce-platos/` ya está en `.gitignore` y `.dockerignore`.
- [ ] Borrar `catorce-platos/` cuando ya no haga falta (está fuera de git).
- [ ] El `README.md` no menciona el cambio de idioma a español (`APP_LOCALE=es`).

## Servicio Celaut (`.service/`)

- [ ] **Nada de esto se ha construido ni ejecutado.** Sin acceso al daemon de Docker y con `core_services.packer` sin configurar (`<SET_ME>`) en `/nodo/config.yaml`, solo se comprobó la sintaxis (`bash -n`, JSON). Primer paso: configurar el packer (o `packer.local: true`) y ejecutar `nodo pack .`.
- [ ] **Persistencia.** Los datos (bucket, secretos) viven en `/var/lib/nutrition` del disco de la instancia. La guía solo garantiza que un directorio *compartido* no sobrevive a un reinicio; no dice si el disco propio sí. Comprobar con `nodo kill` + `nodo execute` antes de fiarse; si no persiste, mover la base y el bucket a un S3/BD externos.
- [ ] **Contraseña por defecto.** `admin@demo.com` / `password` queda expuesta mientras no se cambie (el panel solo se alcanza por `nodo tunnel`, pero conviene cambiarla en el primer acceso).
- [ ] **Restaurar una copia sin probar.** La copia (`.sql.gz`) se restaura con `psql` sobre una base ya migrada; falta probarlo de verdad entre dos instancias y que el volcado cabe en la descarga con una base grande.
- [ ] **Copias automáticas.** Hoy la copia es manual; si el servicio muere sin haber descargado una, se pierde todo. Valorar una descarga periódica (`nodo` + tunel + cron externo).
- [ ] **Red `*`.** El runner escucha en `0.0.0.0:9000` y no se puede cambiar de interfaz sin tocar `docker/agente/runner.py`; no se declara en `api`, así que no se puede tunelar, pero conviene acotar la interfaz.
- [ ] **Recursos por medir.** Memoria (2 GiB), CPU (1) y disco (4 GiB) de `service.json` son un tope fijado por el usuario, con `at_init` igual a `at_most`; comprobar que bastan para una generación de menú real con `nodo observe <instancia>`.
- [ ] **`docker/pruebas/claude-falso.py`** (Claude falso) quedó sin forma de uso al retirar docker compose. Se puede apuntar a él con `AGENTE_CLAUDE`; decidir si se conserva o se borra.
