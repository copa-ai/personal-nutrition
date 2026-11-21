# Pendiente

Lo que queda abierto tras el rediseño de la ficha del menú. Ordenado por importancia.

## Calidad de lo que genera el agente

- [ ] **Probar el contrato v2 con el modelo real.** Las reglas están probadas con entregas escritas a mano y con `claude-falso.py`, pero no con una ejecución real. Mirar si el agente respeta que todas las opciones de una toma compartan categorías y si las explicaciones salen concretas (gimnasio, horarios) y no una plantilla repetida.
- [ ] **Probar el contrato v3 y las skills nuevas con el modelo real.** `ajustes` por opción (que solo salgan cuando aportan, no en todas), `editar-menu` (devuelve el menú entero y toca solo lo pedido) e `intercambiar-menu` (reubica platos y respeta las categorías de la toma). Hoy solo se han probado con `claude-falso.py` y entregas escritas a mano.
- [ ] **Probar el hogar único.** Hecho sin PHP en el sandbox: ejecutar `php artisan test` y `migrate:fresh`, abrir «Mi hogar» (los campos se guardan al salir de ellos) y «Tiempos de cocinado», y ver que `hogar:inicial` crea el hogar en el arranque del servicio. Si ya hay varios hogares por datos antiguos, `Hogar::unico()` usa el más antiguo y los demás quedan sin usarse: decidir si se borran. Las acciones del chat `crear_hogar` y `borrar_hogar` se han quitado y las demás ya no llevan `hogar`.
- [ ] **Probar los platos por opción y la temporada.** Cambios hechos sin PHP en el sandbox: ejecutar `php artisan test` (`EntregaMenuTest`, `EncargoMenuTest`, `MenuPdfTest`; `CreaHogar::entregaValida` ahora lleva 2 platos por opción), mirar el PDF y la ficha con platos, y probar con el modelo real que sale un primero y un segundo que se complementen y fruta y verdura de temporada. La edición a mano y la del chat (`editar_opcion_menu`) aún no tocan `platos`.
- [ ] **Ejecutar `php artisan test` con los tests nuevos.** Se escribieron `EdicionIntercambioTest`, `InterfazEdicionTest` y casos nuevos en `EntregaMenuTest`, `EjecutorAgenteTest` y `MenuPdfTest` sin PHP ni Docker en el sandbox: no se han ejecutado. Ejecutar también `migrate:fresh` con las migraciones de `menu_id`, `cambios`, `tarea`, `instruccion` y `contenido_anterior`.
- [ ] **Ejecutar `php artisan test` y `migrate:fresh` tras la migración a v2.** En la última revisión el sandbox no tenía PHP ni acceso a Docker: solo se pasaron los tests de Python.
- [ ] **Tarifas sin verificar.** Los precios de `admin/config/agente.php` son los de la guía. Faltan los modelos 5.5 de Haiku y Fable. Hasta revisarlos, el coste en dólares es orientativo.

## Interfaz

- [ ] **Modo oscuro sin verificar.** Los colores usan variables de Filament y `color-mix`, así que deberían adaptarse, pero el navegador sin interfaz que usé para las capturas no aplicó el esquema oscuro. Mirarlo a mano.
- [ ] **Elegir opción en un navegador real.** La interacción (botones de radio de cada toma) está probada con Livewire, pero no con clics reales. Comprobar también el foco con teclado: ahora cada opción es un botón con `Tab`; las flechas no cambian de opción.
- [ ] **Resto de pantallas:** ya tienen columnas secundarias ocultables (Perfiles, Alimentos, Lo comido); no se ha visto ninguna en un navegador. La lista de menús se vio antes de poner la interfaz en español.
- [ ] **Móvil sin verificar en navegador real.** La ficha del menú (objetivos de 44 px, navegación y cabecera de día pegajosas, tablas con scroll interno) y el calendario (mes con puntos, semana en lista, detalle como hoja a pantalla casi completa con cierre) se escribieron solo con CSS/Blade, sin PHP ni navegador: probar a 360-430 px en claro y oscuro, y que las alturas pegajosas (4 rem de la barra de Filament) cuadran.
- [ ] **Zona horaria.** Todo corre en `UTC` (constante: la fusión de bases compara `updated_at` sin zona), así que el registro del agente muestra horas UTC. Si se quiere otra, hay que convertirla solo al mostrar.
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
- [ ] **Recursos por medir.** Memoria (1,5 GiB), CPU (1) y disco (4 GiB) de `service.json` son un tope fijado por el usuario, con `at_init` igual a `at_most`; comprobar que bastan para una generación de menú real. El servicio publica `/memoria.json` (cada 15 s: memoria usada, pico observado desde el arranque, pico y límite del cgroup si el guest los expone, y los 10 procesos que más pesan): con el túnel, `curl <túnel>/memoria.json` tras generar un menú y mantener un turno de chat da el pico real. Con ese pico se recorta `mem_limit` (y, si `celaut resize --mem` de la versión de service-library empaquetada lo permite, se pide más memoria al nodo solo cuando haga falta; el Dockerfile fija la v0.6.3, que ya trae `celaut resize --mem BYTES`, pero no se ha ejecutado).
- [ ] **`docker/pruebas/claude-falso.py`** (Claude falso) quedó sin forma de uso al retirar docker compose. Se puede apuntar a él con `AGENTE_CLAUDE`; decidir si se conserva o se borra.
- [ ] **Recarga del saldo de la base sin probar.** `Recarga` usa `celaut metrics`, `cost` y `deposit` de service-library (leídos del código fuente de la v0.2.0 y comprobados contra la v0.6.3; no ejecutados): comprobar en un nodo real que `metrics` da `balance_mu`, que `cost` devuelve `init_maintenance_cost_mu` como un tick de 10 s y que `deposit` mueve saldo del servicio a la base. Falta también un aviso en la página de estado cuando la recarga falla (hoy solo el log de `vigilar-base`).

## Coder (`docs/coder/`)

- [ ] **Nada de esto se ha ejecutado.** Se escribió sin PHP, Docker ni nodo. No se han ejecutado `php artisan test` (`CoderServiceTest`, `CoderPaginaTest`, `SaldoTest`, `SaldoDecisionTest`, `ClavesSshTest`, `ClaveSshHostTest`, `VigilarCoderTest`, `NodoCoderProcesoTest`), ni el empaquetado con el Dockerfile nuevo. Solo se probaron `bash -n`, `docker/pruebas/test-recarga.sh` y los tests de Python del reenviador.
- [ ] **Leer la especificación de un servicio.** `celaut` v0.6.3 no la lee, así que `LectorEspecificacion` devuelve «desconocida» y, con `coder.spec_desconocida=rechazar` (por defecto), no se puede lanzar ningún Coder. Escribir un lector (cliente gRPC de `GetService` o `bee-rpc`) o poner `confirmar`.
- [ ] **Ranura SSH de un hijo.** La CLI no da las ranuras del hijo: se usa la `uri` de `acquire` (primera ranura api) y se comprueba el banner `SSH-2.0-`. Un cliente gRPC con `uri_slot` permitiría elegir por etiqueta `ssh`.
- [ ] **Coste y recursos reales.** `celaut cost` no da memoria, disco ni CPU, y rechaza un servicio que el nodo no tiene (un `.bee` subido no se puede tasar antes). Comprobar en un nodo el JSON real de `acquire`, `cost`, `metrics` y `deposit`.
- [ ] **Parar nutrition no para al Coder.** El nodo no cascada: el Coder queda huérfano y con el montaje roto. Ejecutar `celaut notice --shutdown` en `start.sh` (patrón de Copa) para que `apagar-nodo` corra, o poner `notice_declaration` a false. Además `activity_window` con `ON_CLOSE` para o hiberna el servicio, y un servicio que exporta un recurso no se hiberna.
- [ ] **Propietario y rendimiento en virtiofs.** No se ha comprobado qué propietario tienen los ficheros que crea el Coder (root), si el usuario `app` puede escribir `.coder/authorized_keys`, ni el rendimiento de `vendor/` con `opcache.validate_timestamps=0`. El tar de «Restaurar original» corre como root sobre ficheros que escribe el Coder (riesgo de enlaces simbólicos aceptado).
- [ ] **Tamaño de disco.** `service.json` pide 6 GiB (adivinado). Medir la imagen tras el primer empaquetado y ajustar. Límite del nodo `MAX_DISK_SHARE` 0,5. Un nodo pequeño rechaza nutrition entera por `possible_environment_workload` (3 GiB, 10 GiB, 2 CPU); no se ha comprobado si limita el hash que se lance después.
- [ ] **Lanzamiento y recarga.** Una recarga de la app puede matar un lanzamiento en curso (se recupera con el tiempo máximo de 2400 s o con «Detener» forzado). Una parada forzada puede dejar un lease en el pool.
- [ ] **Límites de subida.** Cuatro números deben coincidir (`config/coder.php`, `config/livewire.php`, Dockerfile y Caddyfile: 512 MB). No se ha probado con FrankenPHP y el nombre de la ruta de subida de Livewire 4 es una suposición.
- [ ] **Comprobación de arranque.** `route:list` no toca la base; un proveedor que consulte la base al arrancar la haría fallar. No probado con PHP real.
- [ ] **Tasa de gasto.** Hoy sale de muestras de saldo (ruido con pocas muestras: no se envía nada). Cambiar por `celaut account --from-seq` (tasa exacta de las filas `maintenance`) cuando se pueda verificar el JSON en un nodo. Cada conexión del túnel cuesta 10000 MU a nutrition y entra en la tasa.
- [ ] **Clave de host.** `CODER_SSH_HOST_KEY` aparece en `argv` del proceso `celaut` unos segundos. Solo permite suplantar el host. Además, la clave solo sobrevive a un reinicio si el disco propio persiste (ver «Persistencia»).
- [ ] **Servicio Coder.** El servicio Coder en sí no existe. Cuando se escriba, seguir `docs/coder/contract.md` (primera ranura api = SSH).
- [ ] **Nodo `celaut` no ejecutado.** Ningún comando `celaut` se ha probado en un nodo vivo (`acquire`, `list`, `stop`, `deposit`, `metrics`), ni que el nodo reenvíe el puerto de la `uri`, ni que SIGTERM llegue a nutrition al parar, ni que el cortafuegos deje ssh del padre al hijo. `setpriv` y el puerto 2222 sin root tampoco se han probado en una VM.
