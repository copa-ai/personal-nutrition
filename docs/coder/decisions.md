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
