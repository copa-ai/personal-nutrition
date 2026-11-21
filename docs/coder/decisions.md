# Coder decision log

This file records the design decisions for the Coder feature.
Each entry has a Context, a Decision, a Reason and an Effect.

## 1. Project tree path and content
Context: The agreed share path is /opt/nutrition/project. The old code path was /opt/nutrition/admin.
Decision: The image copies admin (with vendor), agente, docker, .service, dependencies, docs and README.md into /opt/nutrition/project. It keeps the tests directory. It has no .git and no node_modules.
Reason: The Coder must see the whole repository. A Coder with only admin could not change the service files.
Effect: The share is bigger. The pack has more files (see entry 3).

## 2. Run scripts stay outside the share
Context: The Coder can write all files in the share. start.sh runs as root. The agent kit and the chat translator run as other users.
Decision: start.sh, Caddyfile, bucle.sh, the status page, /opt/agente-kit, /opt/runner and /opt/chat stay at their old paths. The Dockerfile copies them a second time, outside the share. The project copy of docker/ is only for reading and editing by the Coder.
Reason: A Coder could change a script that root runs. It would gain root in the nutrition VM. The Coder is a separate trust domain.
Effect: A change to start.sh in the share has no effect until the next repack. The Laravel code reloads without a repack. Rejected option: run start.sh from the share.

## 3. Pack context
Context: The packer builds the image from the include list of pack_config.json. It had only admin, agente and three docker subdirectories.
Decision: The include list now has admin, agente, dependencies, docker, docs, README.md and .service. The ignore list loses "tests/". It keeps vendor/, because the vendor stage builds vendor. There is no root .dockerignore. The file .service/.dockerignore stays as it is.
Reason: A COPY of a path that is not in the context fails. The old ignore rule removed tests, but the share must hold them.
Effect: The pack includes docker/pruebas and docker/Dockerfile.agente. It also includes dependencies/postgres sources (small, 12 KB).

## 4. Original copy as a tar outside the share
Context: A mount hides the image content at its path.
Decision: The Dockerfile makes /opt/nutrition/project.orig.tar.gz as its last step. It runs chmod -R a+rX first. The tar root is the project directory contents (tar -C project .).
Reason: The tar is the only copy of the original that stays visible after the mount. A tar of the final tree keeps the vendor and the built Filament assets.
Effect: The restore action must extract with -C /opt/nutrition/project. The image holds the tree twice (tree and tar). Disk size covers this (entry 7).

## 5. Writable data stays outside the share
Context: Laravel writes to storage and to bootstrap/cache.
Decision: No change to the env variables that start.sh already sets: LARAVEL_STORAGE_PATH and the APP_*_CACHE paths point to /var/lib/nutrition/admin. Filament assets are built in the image under admin/public. The app user only reads the share.
Reason: The share ownership on virtiofs is not verified. The Coder may create root-owned files. A read-only need is safe in both cases.
Effect: start.sh runs chmod -R a+rX on the share at each start. If chmod fails, it logs a warning and continues. A missing artisan file stops the start with a clear error on the status page.

## 6. Restartable loop script
Context: The web server and the queue workers must restart after a kill. The old queue loop used sh -c and left no PID.
Decision: A new script, docker/servicio/bucle.sh, runs one command in a loop. It writes the PID of the child (not of the loop) to a file. It waits one second between restarts. On TERM it kills the child, removes the file and exits. start.sh uses it for frankenphp and for both queue workers. The PID files are web.pid, cola-default.pid and cola-chat.pid in /var/lib/nutrition/coder.
Reason: The watcher needs the PID of the real process. A POSIX sh script needs no extra tool. The TERM handler prevents orphan workers when the service stops.
Effect: A crash loop of the web server no longer ends the service (before, wait -n ended it). The status page and the log still show the restarts. start.sh deletes old PID files at start. I tested the script with a sleep command: kill, restart and stop work.

## 7. Disk size 6 GiB
Context: The node counts the exporter disk as image plus shared directories. It checks the value on every tick. The old value was 4 GiB.
Decision: at_init and at_most disk_space are 6 GiB (6442450944 bytes).
Reason: The project tree is about 8 MB of source plus vendor. The image holds it twice (tree and tar), and the materialized share holds a third copy. The Coder can also add packages and files. I added 2 GiB as a margin. I did not measure the real image size.
Effect: The maintenance price rises a little, because the price follows resources. The value is a guess: measure the real size after the first pack and adjust. The node limit MAX_DISK_SHARE (0.5) also applies.

## 8. Slot for the Coder admission check
Context: A node must hold room for a Coder child. The agreed bound is 3 GiB memory, 10 GiB disk and 2 CPU.
Decision: service.json gets possible_environment_workload with one clause that has one workload and only at_most. It is separate from the postgres clause in initial_environment_workload.
Reason: The check is an admission check at launch of nutrition. It has no cost and no reservation. at_init is not needed.
Effect: A node that is too small refuses nutrition itself. It is not verified that the bound limits which hash the panel launches later.

## 9. SSH slot position
Context: The SSH slot is the fourth api slot, port 2222, tcp, protocol_stack [["ssh"]].
Decision: I added it after the cards slot and used the exact JSON from the verification. The slot has no prose.
Reason: The order of the existing slots stays the same. The first slot (HTTP, 8000) remains the first address of nutrition.
Effect: The forwarder that listens on 2222 comes in a later step. Until then, the port has no listener.

## 10. Watcher is one bash script with a sourceable core
Context: The reload logic needs small functions and a local test. No PHP or docker exists on the development machine.
Decision: docker/servicio/recarga.sh holds all logic in functions. It runs the loop only when run as a script. The RECARGA_* variables change paths and commands. docker/pruebas/test-recarga.sh sources it with fake php, fake health and web processes made by bucle.sh.
Reason: Tests run the real code and need no root. Bash is already in the image. A Python watcher would add a second language for the same job.
Effect: The test covers good reload, syntax error, boot error, health rollback, auto off, reload now and restore original. It does not run real PHP, FrankenPHP or setpriv.

## 11. Watcher lives outside the share, in a loop that restarts it
Context: start.sh stops the whole service when one piece ends (wait -n). The watcher runs as root and reads files the Coder writes.
Decision: The Dockerfile copies recarga.sh to /opt/nutrition/recarga.sh, outside the share (same rule as entry 2). start.sh starts it in a small bash loop that restarts it after 5 seconds and stops it on TERM. It passes the app environment variable names in RECARGA_NOMBRES and the values in its own environment.
Reason: The Coder must not change a root script. A watcher crash must not stop the app. The watcher needs the app environment only for php and artisan.
Effect: The watcher process holds the database password in its environment, as root. Rejected option: bucle.sh for the watcher (it writes a PID file as root in a directory that the app user owns).

## 12. Root-only state directory
Context: /var/lib/nutrition/coder belongs to the app user. A compromised app could plant a symlink there, and root would follow it when it writes.
Decision: The snapshot, its stamp and the manifests live in /var/lib/nutrition/coder-root (root, mode 700). Only the flags and recarga.json stay in the coder directory. The watcher writes recarga.json through the app user (tee and mv with setpriv).
Reason: Root never writes to a path that the app user controls.
Effect: This differs from the first plan, which kept the snapshot in /var/lib/nutrition/coder. The panel does not need the snapshot. Root still reads and removes the flag files. A removal of a symlink removes only the link.

## 13. File names and status fields
Context: The panel step must read and write the control files.
Decision: Flags are auto-recarga (content 0, off, no or false means OFF; a missing file means ON), recargar-ahora and restaurar-original. The status file is recarga.json. Its fields are in the header of recarga.sh: estado, resultado, mensaje, fecha, fecha_unix, auto, pendiente, cambios, arbol_vivo, arbol_original, igual_original, ultima_buena, recargas.
Reason: Spanish names follow the rest of the panel. A flag file is simple for PHP to create and for bash to test.
Effect: The panel writes auto-recarga as the app user. The watcher removes the two action flags when it takes them. The panel must not rely on a reply before the next poll (5 seconds plus the reload time).

## 14. Cheap fingerprint, content hashes only on change
Context: The poll runs every 5 seconds. A content hash of vendor would be slow.
Decision: The poll hashes path, size and mtime (find -printf, sha256sum) of the watched paths. Content manifests (sha256 of each file) are made only after a stable change and after a reload. The original manifest is made from the tar and cached in the root-only directory.
Reason: Polling costs little. The panel still gets exact "changed or not" data and a changed file count.
Effect: A touch without a content change starts one reload. The count of changed files is exact. Watched paths do not include vendor, public or the other repository folders.

## 15. Boot check is artisan route:list
Context: A syntax check does not find a broken provider, a bad route file or a missing class.
Decision: The boot check runs "php artisan route:list --json --no-interaction" as the app user. It boots the app, runs every provider and loads every route file. It does not touch the database.
Reason: "artisan --version" does not boot the providers, so it finds too little. A command that reads data would fail when the database is down and would block a good reload. start.sh never runs route:cache, so the command reads the real route files.
Effect: A run takes about one to two seconds of PHP. Not verified on real PHP: a provider that queries the database at boot would fail this check.

## 16. php -l only on files newer than the last good reload
Context: Checking every PHP file on each change is slow on virtiofs.
Decision: The stamp file ultima-buena.stamp has the time of the start of the last good reload. php -l runs on watched .php files newer than it. A missing stamp means all files. A failed check leaves the stamp unchanged, so the files are checked again next time. Blade and JSON files are not checked.
Reason: The stamp uses the start time of the cycle, so an edit during a cycle is checked in the next one.
Effect: A restore of the original removes the stamp, so every file is checked once.

## 17. Reload method and health check
Context: The loops from entry 6 restart a child that ends.
Decision: The watcher runs optimize:clear, sends TERM to the FrankenPHP PID and waits for a new PID file (max 40 s). It sends TERM to both queue workers and does not wait: a worker ends its current job first, then bucle.sh starts it with the new code. The health check is an HTTP 200 on http://127.0.0.1:8000/up, one try per second, 30 tries.
Reason: A menu job can last hours. A wait for the worker would block the reload. The route /up exists in bootstrap/app.php and needs no login.
Effect: A queue job that is running keeps the old code until it ends. optimize:clear also clears the database cache store (cache:clear): a failure only gives a log warning. During the web restart the app is down for a few seconds.

## 18. Rollback and error states
Context: A change can pass the checks and still break the app.
Decision: After a failed health check, the watcher restores the last good snapshot, runs optimize:clear, restarts web and queue, and checks health again. Result revertido on success. Result error_salud when there is no snapshot or the restored code also fails. After a failed check or a rollback, the watcher stores the fingerprint as seen, so it does not retry the same code every 5 seconds. A new edit starts a new attempt. At start, if the app is healthy and there is no snapshot, the current code becomes the first snapshot.
Reason: This prevents a loop of reload and rollback. The first snapshot gives the first change a safe base.
Effect: After an error the old code runs and the status shows the message until the next change or a manual reload.

## 19. Restore original details
Context: The tar holds the whole project, including vendor. The Coder can add files and packages.
Decision: The watcher extracts the whole tar over the share with --no-same-owner and without .coder, then removes files that the tar does not hold in the watched paths and in admin/vendor. It then runs a normal reload with no snapshot rollback. A failed health check gives error_salud. Rejected option: delete and rebuild vendor (large and slow with the app running).
Reason: Extraction replaces changed vendor files. The prune step removes added vendor files. composer.lock and vendor match again.
Effect: Files outside these paths (docs, agente, docker) are replaced but extra files there stay. Empty directories stay. Root extracts files that the Coder wrote: tar can follow a symlink that the Coder planted. Accept this risk for now. File names with a newline are not handled.
