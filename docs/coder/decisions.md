# Coder decision log

This file records the design decisions for the Coder feature.
Each entry has a Context, a Decision, a Reason and an Effect.
Some entries also have a line "Rejected options".
Entries 1 to 69 come from the build steps (image, reload, ssh, coder, funds, panel).
Entries 70 to 85 record the agreed design decisions and the limits that the build did not record.

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
Decision: The watcher runs optimize:clear, sends TERM to the FrankenPHP PID and waits for a new PID file (max 40 s). It sends TERM to both queue workers and does not wait. A worker ends its current job first. Then bucle.sh starts it with the new code. The health check is an HTTP 200 on http://127.0.0.1:8000/up, one try per second, 30 tries.
Reason: A menu job can last hours. A wait for the worker would block the reload. The route /up exists in bootstrap/app.php and needs no login.
Effect: A queue job that is running keeps the old code until it ends. optimize:clear also clears the database cache store (cache:clear): a failure only gives a log warning. During the web restart the app is down for a few seconds.

## 18. Rollback and error states
Context: A change can pass the checks and still break the app.
Decision: After a failed health check, the watcher restores the last good snapshot, runs optimize:clear, restarts web and queue, and checks health again. Result revertido on success. Result error_salud when there is no snapshot or the restored code also fails. After a failed check or a rollback, the watcher stores the fingerprint as seen, so it does not retry the same code every 5 seconds. A new edit starts a new attempt. At start, if the app is healthy and there is no snapshot, the current code becomes the first snapshot.
Reason: This prevents a loop of reload and rollback. The first snapshot gives the first change a safe base.
Effect: After an error the old code runs and the status shows the message until the next change or a manual reload.

## 19. Restore original details
Context: The tar holds the whole project, including vendor. The Coder can add files and packages.
Decision: The watcher extracts the whole tar over the share with --no-same-owner and without .coder. Then it removes files that the tar does not hold in the watched paths and in admin/vendor. It then runs a normal reload with no snapshot rollback. A failed health check gives error_salud. Rejected option: delete and rebuild vendor (large and slow with the app running).
Reason: Extraction replaces changed vendor files. The prune step removes added vendor files. composer.lock and vendor match again.
Effect: Files outside these paths (docs, agente, docker) are replaced but extra files there stay. Empty directories stay. Root extracts files that the Coder wrote: tar can follow a symlink that the Coder planted. Accept this risk for now. File names with a newline are not handled.

## 20. Forwarder is a Python asyncio script with no dependency
Context: The SSH slot needs a byte pipe from port 2222 to the Coder. The image has python3 and no extra package is needed.
Decision: docker/servicio/ssh-proxy.py uses only the standard library. Settings come from arguments, then SSH_PROXY_* variables, then defaults. Rejected options: socat (not in the image, no live check of the destination file) and a PHP pipe (heavy, runs as the app user).
Reason: The script is short, testable with unittest and runs with the empty environment.
Effect: The unit tests in docker/pruebas/test_ssh_proxy.py run without a node.

## 21. The destination file is read for each connection and each second
Context: The Coder can stop, or a new Coder can start, while pipes are open.
Decision: The forwarder reads destino.json for each new connection. A watcher task reads it every second. A pipe whose target is not the current target closes. A removed or invalid file closes all pipes. The same content again keeps the pipes.
Reason: This needs no signal from the panel or from vigilar-coder. The command only writes or removes the file.
Effect: A pipe closes within about one second of a change. A pipe to a Coder that died stays open until the command removes the file or the TCP connection breaks.

## 22. Limits and timeouts
Context: A bad client can open many connections and pay nothing, but nutrition pays 10000 MU for each tunnel connection.
Decision: Defaults: 16 open pipes, 10 s connect timeout, 14400 s (4 hours) idle timeout, 4096 bytes maximum for the destination file. The idle time counts both directions together. A connection over the limit closes at once with one log line.
Reason: VS Code Remote-SSH opens several connections. SSH sessions can be quiet for hours. A shared idle clock stops a pipe only when both sides are quiet.
Effect: The values are settings (--max, --idle, --connect-timeout). Not tuned on a real node.

## 23. Strict host validation, no resolution rules
Context: The destination file is written by the app user from the output of the node, but the forwarder must not trust it.
Decision: The host must be an IP address (v4 or v6). Or it must be a name of letters, digits, dots and hyphens that starts and ends with a letter or digit. The port must be an integer from 1 to 65535. Other types (text, boolean, float) are refused. The match uses the whole string, so a trailing newline fails.
Reason: A bad file must not make the forwarder connect to an odd target or write odd text in the log.
Effect: The forwarder accepts loopback and private addresses. It does not limit the target to the Coder: only the app user can write the file.

## 24. Forwarder user sshproxy and a group on the coder directory
Context: The app user owns /var/lib/nutrition/coder (mode 750) and writes destino.json. The forwarder needs to read it. Group app is not suitable: /__config__ is readable by group app.
Decision: The Dockerfile creates user and group sshproxy (uid and gid 1003, no login shell). start.sh sets the directory to app:sshproxy, mode 750. The file is mode 644 and the app user owns it. The forwarder starts through the "como" function with an empty environment (env -i).
Reason: The forwarder gets read access to this directory only. It cannot write the destination file or change the directory.
Effect: The forwarder can also read the other control files in this directory (PID files, flags, recarga.json). They hold no secret. The panel step must write destino.json by atomic rename in the same directory.

## 25. Restart loop and place in start.sh
Context: Every piece that ends stops the whole service (wait -n), but the forwarder is not essential for the web app.
Decision: start.sh runs ssh_proxy_bucle, which restarts the forwarder after 3 seconds. TERM kills the child. The script and the user are outside the shared project, so a Coder cannot change them. The forwarder starts after the web and queue are ready and does not add a phase to the status page.
Reason: A crash of a debug path must not take down the menu service.
Effect: A crash loop shows in app.log. Not verified on a real VM: setpriv and the port bind at 2222 as a non-root user (port above 1023, so no capability is needed).

## 26. The host key comes from PHP sodium, not from ssh-keygen
Context: The image has no ssh-keygen. The Coder needs an OpenSSH private key in CODER_SSH_HOST_KEY.
Decision: App\Services\Coder\ClaveSshHost builds an ed25519 key in the OpenSSH file format with the sodium extension. Rejected options: add openssh-client to the Dockerfile (a package and a sub-process for one call) and PHP openssl (it cannot write the OpenSSH format).
Reason: Sodium is part of the PHP image. The format is short. A Python copy of the code was read by ssh-keygen -y on this host and gave the right public key.
Effect: No image change. The PHP code itself did not run here. The test ClaveSshHostTest runs ssh-keygen when it exists.

## 27. The host key is stored in a subdirectory that the app user owns
Context: /var/lib/nutrition/secretos was root only (mode 700). The panel code runs as the app user and must write the key there.
Decision: start.sh sets secretos to mode 711 and makes secretos/coder (owner app, mode 700). The key file is secretos/coder/ssh_host_ed25519, mode 600, written by rename.
Reason: The app user must enter secretos to reach its subdirectory. Mode 711 lets it enter and does not let it list. Files directly in secretos stay mode 600 for root.
Effect: Other users (agent, chat) can test if a name exists in secretos, but cannot read it. The key is created at the first launch, not at start.

## 28. Spec reading is a replaceable class that returns "unknown"
Context: celaut v0.6.3 cannot read the spec of a service (see the verification result). The agreed rule says to refuse a service with no guest share "project".
Decision: App\Services\Coder\LectorEspecificacion returns null (unknown). The default value of config coder.spec_desconocida is "rechazar": a launch is refused with a Spanish message. The value "confirmar" lets the user confirm in the panel that the service mounts the share. Rejected options: parse the .bee file in PHP (protobuf and multiblock code, too large) and a Python helper with bee-rpc (not in the image).
Reason: The rule stays strict. A real reader can replace the class later with no change in Coder.
Effect: With the default, no Coder can launch until a reader exists or the setting is "confirmar". This blocks the feature on purpose. The panel step must show the refusal text.

## 29. Coder state uses Ajuste keys with the prefix coder.
Context: The state must survive a restart of the web process and be readable by the panel, the job and the command.
Decision: Keys: estado (none, launching, running, error), instancia, servicio, tipo, referencia, lease, uri, error, lanzado_en, enviado_mu, horas_vida, saldo_mu, claves_ssh. The stop action sets the keys of one life to null and keeps claves_ssh.
Reason: Ajuste::fijar audits each change. No new table or migration is needed.
Effect: Each state change writes an audit row "ajuste.cambiado". The funding step writes horas_vida, saldo_mu and enviado_mu. Package files stay after a stop, so a new launch is quick.

## 30. The settings table is shared, so the Coder has an owner instance
Context: The table "ajustes" is in the core schema of the shared database. Two devices on one database see the same coder.* rows.
Decision: A launch stores coder.instancia = Instancia::id(). If the state is not "none" and the owner is another instance, estado() sets ajeno=true. Launch, stop and the watcher then refuse or skip.
Reason: A second device must not read a lease from another node and mark the Coder as dead, or stop it.
Effect: One Coder for each shared database at a time. A Coder of a device that is lost stays "ajeno" until the owner stops it. A manual change of coder.estado to none clears it.

## 31. Launch runs in a queue job and a stuck launch times out
Context: celaut acquire can take minutes. A web request cannot wait for it.
Decision: App\Jobs\LanzarCoder calls Coder::lanzar (tries 1, no timeout). Coder::lanzar sets the state to "launching" first. The watcher marks a launch older than coder.lanzamiento.colgado_s (2400 s) as "error". When a refusal occurs before any change, the job stores the reason in coder.error.
Reason: If the queue worker dies or the reload watcher restarts it, the state must not stay "launching" for ever.
Effect: A reload of the app can kill a launch that is in progress. The watcher then gives "error" after 40 minutes, or the user stops with force. The lease of such a launch can stay in the pool.

## 32. The SSH address is the uri of acquire, checked by the SSH banner
Context: celaut gives no slot list for a child. `uri` is the first address of the first api slot.
Decision: The launch connects to the uri and reads a line that starts with SSH-2.0-. It retries for 180 s. If the test fails, the service is stopped, the state is "error" and no destino.json is written. The contract says that the ssh slot is the first api slot.
Reason: A wrong first slot would send the user to a wrong service. The banner test needs no node command.
Effect: A Coder whose first slot is not SSH never gets a target. The test connection is made from nutrition to the child and costs nothing. Not verified on a node.

## 33. Rules for the authorized keys
Context: The text of the panel is written in a file that the Coder reads as authorized_keys, as root in its VM.
Decision: ClavesSsh accepts a line only if it matches "type base64 [comment]" as a whole. Types: ssh-ed25519, ecdsa-sha2-nistp256/384/521, ssh-rsa. The blob must start with the same type name. The comment has 1 to 64 characters from A-Z a-z 0-9 @ . _ : + = - and space. Blank lines are skipped, equal keys count once, the maximum is 20, and one bad line rejects the whole text. Rejected option: keep the good lines and drop the bad ones.
Reason: A quote, a comma or a control character in the comment could add an option. Silent drops hide errors.
Effect: Comment lines with # and keys with options fail. The user sees the line number. An empty text is valid and removes all keys.

## 34. The keys are written at each save and at each launch
Context: The share is new at each start of nutrition, so .coder/authorized_keys is lost. The key list is also in Ajuste.
Decision: guardarClaves writes the file by rename (mode 644) and then stores the list. Launch writes the file again from the list before it starts the service. If the write fails, nothing is started.
Reason: A launch with no keys file would give a Coder that nobody can enter, and it would cost money.
Effect: The share must be writable by the app user. This is not verified on virtiofs (see the verification notes).

## 35. Package upload rules
Context: The panel accepts a .celaut.bee file. A path from a form field must not reach celaut.
Decision: guardarPaquete accepts only a name that ends in .celaut.bee and a size up to coder.paquete.tamano_maximo_mb (512). It copies the file to coder/paquetes/<random>.celaut.bee (mode 600) and runs celaut inspect. Launch accepts a path only if its real path is inside that directory. A symbolic link to the outside fails.
Reason: A stored file with a random name cannot be chosen by the user. The inspect call rejects a file that is not a package.
Effect: The PHP and Livewire upload limits are separate and must be at least 512 MB. The panel step must set them. Uploaded files are never deleted by nutrition.

## 36. The confirm step shows the cost and the resource bound, not the real resources
Context: celaut cost prints four values and no memory, disk or CPU. The node refuses a cost for a service that it does not hold.
Decision: preparar returns the cost array (or null with the reason of the node), the admission bound from config coder.recursos, and two spec fields. The real resources are "conocidos: null".
Reason: A made-up value is worse than an unknown value.
Effect: For an uploaded package that the node never saw, the panel shows "cost unknown". The start is then bounded only by the initial MU setting.

## 37. Stop closes the SSH access first, and a forced stop exists
Context: A stop that the node refuses must not leave the SSH target open.
Decision: parar removes destino.json before the node call. If the node refuses, the state becomes "error" and the lease stays, so a new stop can run, and a new launch is refused. The option $forzar clears the state anyway. A stop while the state is "launching" needs $forzar.
Reason: Access closes at once. The user is never stuck with a lease that nutrition cannot stop.
Effect: A forced stop can leave a lease in the pool. The watcher does not track it any more.

## 38. A dead Coder gets the state "error" with no lease
Context: The watcher finds that the lease is not in the pool any more. The lease number has no use then.
Decision: The watcher removes destino.json, sets "error", stores the reason and clears lease and uri. A launch is allowed when the state is "error" and the lease is null. It is not allowed with an "error" that keeps a lease.
Reason: A stop of a lease that does not exist would fail for ever.
Effect: The panel shows the reason until the user launches again. If the node does not answer, the watcher changes nothing.

## 39. The watcher runs in start.sh every 60 seconds
Context: The Coder can die from lack of balance at any time. The forwarder keeps open pipes until destino.json changes.
Decision: start.sh runs nutrition:vigilar-coder as the app user in a loop with a 60 s pause. With no Coder, the command only removes a stale destino.json and makes no node call.
Reason: The load is small. The same loop will later hold the funding rules.
Effect: A dead Coder closes its SSH pipes in less than about 61 s. The command calls celaut list once for each pass when a Coder runs.

## 40. The service class is built from config in the container
Context: Nodo has string constructor arguments and the container cannot fill them from config.
Decision: AppServiceProvider binds Coder::class to Coder::desdeConfig(). Tests replace it with $this->app->instance(). Fakes are subclasses of Nodo, SondaSsh and LectorEspecificacion in tests/Support.
Reason: The command and the job get the class by injection and need no static call.
Effect: A fake node needs no shell script. The real process call is tested in NodoCoderProcesoTest.

## 41. One class for the money rules, with a pure core
Context: The rules must be easy to test. The code that moves balance needs the node, the settings and the audit log.
Decision: App\Services\Coder\Saldo holds both. The static method decidir takes plain values and returns permitted, reason, failed control and maximum amount. It reads no setting and calls no node. Saldo takes Nodo and Coder in its constructor.
Reason: A pure method is tested with many cases and no fake. The transfer code is tested with the fake node.
Effect: The panel step calls addBalance, guardarLimites and status. The command calls vigilar. Rejected option: put the rules in Coder, which already holds the launch code.

## 42. Setting keys for the panel
Context: The panel step binds to the settings.
Decision: The user edits three keys. They are coder.tope_mu (total cap, digit string, null means default), coder.porcentaje_max (integer 1 to 100) and coder.horas_minimas (integer 0 to 8760). The panel calls Saldo::guardarLimites, which validates and calls Ajuste::fijar. The defaults are in config coder.saldo: 24 hours of cap with margin 1.25, 20 percent, 48 hours.
Reason: One method validates all three values together. A bad value changes nothing.
Effect: Other keys are written by Saldo only: coder.muestras_saldo, coder.depositado_hijos_mu, coder.precio, coder.ultimo_rechazo, coder.saldo_mu, coder.saldo_nutrition_mu, coder.horas_vida and coder.enviado_mu. The panel only reads them, through status().

## 43. The cap rule is applied as written
Context: The agreed rule says MU sent during this life plus the current balance must not pass the cap. Sent MU that is still in the balance counts twice.
Decision: I kept the rule as written. "Sent" means the top-ups only. The MU of the launch (initial balance) are not in it, but they are in the current balance.
Reason: The rule is agreed. The double count makes it stricter, never looser. With the default cap (24 hours with margin) the Coder lives about one day.
Effect: The real total that nutrition pays for one Coder is about half of the cap plus the launch MU. A user who wants more raises the cap in the panel.

## 44. The life control looks at the life after the transfer
Context: The agreed rule refuses when the life of nutrition now is under X hours. A transfer can push a healthy nutrition under X.
Decision: The control refuses when nutrition would keep less than X hours of burn after the transfer. It needs balance minus amount at least X hours times 3600 times the burn rate. With the life already under X, the maximum is 0.
Reason: The goal of the control is that nutrition never goes under X because of the Coder. The check on the life now would not give that.
Effect: The rule is stricter than the agreed one. The boundary is exact: life of exactly X hours after the transfer passes. The check uses integer MU, so the rounding goes up (more MU kept).

## 45. Maximum amount, and a smaller transfer in the loop
Context: A refusal for the full target would leave the Coder without balance although part of it is allowed.
Decision: decidir always returns the largest amount that passes the three controls. addBalance never changes the amount: if it is too large, it refuses and says the maximum. The loop sends the maximum instead of the target when the maximum is at least one hour of maintenance (or the whole gap, if smaller).
Reason: A person who types an amount expects that amount or a clear refusal. The loop has no person: a partial top-up is better than none, but a transfer of a few MU each pass is noise.
Effect: A refusal of the loop means that even one hour does not fit under the controls.

## 46. Burn rate from balance samples, not from the ledger
Context: The verification found a better method: celaut account with --from-seq gives the movements, so the rate of the maintenance rows is exact. Nodo has only the cheap read (head and balance).
Decision: I used samples as the agreed design says. A sample is time, nutrition balance and the total that nutrition deposited in its children. Burn between two samples is balance fall minus the deposit delta. Rate is total burn divided by total time.
Reason: It needs no new Nodo method and no parsing of unverified JSON. A read costs about 100 MU, and the ledger read costs 100 MU plus one per row.
Effect: The estimate includes every cost of nutrition, such as tunnel opens. It is noisy for a few samples. Rejected option for now: the ledger method. It can replace tasa() later with no change to the controls.

## 47. The deposit counter covers Recarga too
Context: nutrition also deposits balance into the PostgreSQL child (App\Services\BaseCompartida\Recarga). Those MU would look like burn.
Decision: One counter, coder.depositado_hijos_mu, counts every deposit of nutrition. Saldo and Recarga both add to it. Recarga calls Saldo::anotarDeposito inside a try block, so an error there never stops the database top-up.
Reason: The rate must not depend on who deposited.
Effect: The counter is read before the balance. A deposit between the two reads can only raise the burn of one interval. The next interval then shows a negative burn and is dropped. The read-and-add of the counter is not atomic across processes. The error is small and goes to the safe side.

## 48. When the rate is unknown
Context: Too few or bad samples must never allow a transfer.
Decision: The rate is unknown (null) in these cases. There are fewer than 4 samples. The samples span less than 600 s. Fewer than 3 intervals are valid. The rate is 0 or negative. An interval with no time or a negative burn (a refund, a credit) is dropped. All limits are in config coder.saldo.
Reason: A refund or a credit hides burn. With a long list one dropped interval is acceptable. With the minimum list it is not.
Effect: After a launch, the first top-up waits about 15 minutes. The initial balance of the launch covers that time (about 1 hour by default). The list keeps at most 72 samples and drops those older than 12 hours.

## 49. Volatile values are written without an audit row
Context: Ajuste::fijar writes an audit row on each change. A sample every 300 s, with the whole list, would fill the audit log.
Decision: Saldo writes samples, counters, balances, price and the last refusal with a private method that calls updateOrCreate directly. The three limits and the launch values still use Ajuste::fijar.
Reason: The audit log is for decisions and money moves, not for readings.
Effect: Each transfer and each refusal has its own audit row (see 53). A changed limit has the standard setting row.

## 50. Price source and default cap
Context: The default cap is 24 hours of maintenance at the current price. celaut cost gives three values and no resources.
Decision: The price is max_maintenance_cost_mu of the running service (one period of 10 s at the largest resources). The class stores it in coder.precio with its read time and reads again after 600 s. Default cap = price times 8640 periods times 1.25. The hours left of the Coder use the same price. The panel view uses stored values only.
Reason: The largest price is the safe bound. A page view must not wait for the node.
Effect: Hours left of the Coder are a lower estimate. Until the first read, the cap is unknown and nothing is sent. If the node refuses the price read, the old price stays. The period (10 s) comes from config nutrition.recarga.periodo_s; a real period can last longer, so the hours are an estimate.

## 51. Target and threshold of the loop
Context: A deposit on each pass would add an audit row every 5 minutes.
Decision: The loop aims at 12 hours of maintenance (config coder.saldo.objetivo_horas). It tops up only when the balance is under half of the target. It then sends the gap to the target, limited by the controls.
Reason: The target is under the default cap, so the loop alone does not use it all. A threshold gives few and larger transfers.
Effect: The Coder balance goes between 6 and 12 hours. A user who wants more adds balance by hand.

## 52. Record first, deposit second, roll back on failure
Context: A crash between the node call and the record would let the cap be passed again.
Decision: enviar adds the amount to coder.enviado_mu and to the deposit counter before the call to celaut deposit. If the node refuses (success false) or does not answer, it subtracts the amount again.
Reason: A crash can only make the counters too high. A counter that is too high only makes the controls stricter.
Effect: A failed deposit uses no cap. It has an audit row (coder.saldo_fallo) and shows in the panel as the last refusal with the control "deposito".

## 53. Audit events and silence for the loop
Context: Every refusal must be audited. A loop that is refused for the same reason would write a row every 300 s.
Decision: Events: coder.saldo_enviado, coder.saldo_rechazado (control, reason, amount, source, maximum, balances) and coder.saldo_fallo. A refusal of a user action is always audited. A refusal of the loop is audited in two cases. The control or the source changes. Or the last audit of it is older than one hour. The last refusal is always stored for the panel.
Reason: The log keeps every change of reason and every hour of a lasting refusal. It does not fill with copies.
Effect: With a refusal that lasts a day, the log has about 24 rows from the loop. This is a small step away from "every refusal" and it is only for repeated loop refusals.

## 54. A lock for all transfers
Context: The panel (web process) and the loop can decide at the same time on the same balance.
Decision: addBalance and topUpToTarget run inside Cache::lock("coder-saldo", 180 s). A busy lock gives a Spanish error for the panel and the action "ocupado" for the loop. If the cache cannot give a lock, the code runs without it.
Reason: Two transfers with the same balance read could pass the cap together.
Effect: Both processes run on one VM and share the file or database cache. A lock that a crash leaves is free after 180 s.

## 55. Two loops in start.sh
Context: A dead Coder must close its SSH pipes in about one minute. The funding pass can run every 300 s, like vigilar-base.
Decision: The command nutrition:vigilar-coder has the option --saldo. Without it, it only checks the lease (loop of 60 s). With it, it checks the lease and then samples and funds (new loop of 300 s). Both loops end with "|| true" and the command always exits with success. Errors go to the output.
Reason: One command keeps the logic in one place. Two periods match the two needs.
Effect: The first funding pass runs at start, not after 300 s. The node gets one celaut list more per 300 s.

## 56. Samples before and after a launch or stop
Context: The launch charge (initial balance and build cost) leaves the nutrition balance in one step. It is not burn.
Decision: Saldo samples at every pass, with or without a Coder. The launch code clears the samples after the node accepts the start. Stop and launch clear the last refusal and the stored price.
Reason: Samples before the launch would show a large false burn. With no Coder the read costs about 100 MU per pass, and it gives a rate before the first launch.
Effect: A sample taken during a long launch is lost. After a stop, the refund credit is dropped as a negative interval.

## 57. Number limits and input rules
Context: The amounts come from the panel, from the node and from settings. PHP integers have 64 bits.
Decision: Every amount is an integer or a string of digits. A balance above 10^15 MU is cut to 10^15. A request above 10^15 is refused as too large. Signs, decimals, exponents, spaces inside and non-text values are refused. The percentage is cut to 0 to 100 inside the rule.
Reason: With these limits no product or sum can overflow. A refusal is always better than a wrong number.
Effect: The control "cantidad" is checked before all others and has its own reason text. The three real controls keep the agreed order.

## 58. One page, three services, no rules in the page
Context: The page must start, stop, fund and reload the Coder. The rules already exist in Coder, Saldo and the watcher.
Decision: CoderPagina (App\Filament\Pages) only reads input and calls Coder, Saldo and the new ControlRecarga. It has no money rule and no launch rule of its own. Class name: CoderPagina, as ListaCompraPagina, because the name Coder is taken by the service. Group Ajustes, slug coder, sort 12.
Reason: One rule in one place. A rule copy in the page would drift.
Effect: The page checks the guest tag before the launch only to hide the button. Coder::lanzar has the last word and the job refuses again.

## 59. ControlRecarga holds the watcher files
Context: The flags and recarga.json must be written and read the same way by every caller. The page should stay thin.
Decision: App\Services\Coder\ControlRecarga writes auto-recarga, recargar-ahora and restaurar-original, and reads recarga.json. It reuses Coder::escribirAtomico, which is now public static. Rejected: a second atomic writer.
Reason: The step asked for no duplicate logic. The write by rename is already tested.
Effect: A flag is never half written. A missing control directory gives a Spanish error and no crash.

## 60. recarga.json is untrusted input
Context: A crash or a Coder with write access can leave a missing, partial or wrong recarga.json. The page prints some of its text.
Decision: ControlRecarga::estado gives a default for each field. It checks each type and keeps only known states and results. It cuts the message to 400 characters, removes control characters, and accepts a tree hash only as 64 hex characters. A file over 64 KiB is ignored. Blade escapes the text.
Reason: The page must render in all cases, and a field must never become markup.
Effect: Without a valid file, the page says "sin datos" for the watcher. It still works.

## 61. Locked properties and a second check on confirm
Context: A browser can change every public property of a Livewire page.
Decision: preparacion and rutaPaquete are #[Locked]. The confirm step runs Coder::preparar again, checks the block rule again, and dispatches the normalized reference from that result, never the typed text. Rejected: trust the stored preparation.
Reason: The cost and the spec result can change between the two steps. A client must not fake a clean preparation.
Effect: One more node call at confirm. A client cannot skip the block rule. The package path still goes through Coder::referencia, which accepts only files in the packages folder.

## 62. Upload path: Livewire upload, random name, no client path
Context: The user uploads a .celaut.bee file up to 512 MB.
Decision: The page uses WithFileUploads and a plain file input. On upload it calls Coder::guardarPaquete with the temporary file and the client name. The client name only decides the extension check. The stored name is random. The temporary upload is deleted at once, also after an error. Rejected: the Filament FileUpload field (it needs a form and a disk for a file that must not stay on a public disk).
Reason: guardarPaquete already checks extension, size and the id.
Effect: The client file name never reaches the file system.

## 63. Upload limits in four places
Context: The default limits are too small. Livewire allows 12 MB. PHP allowed 16 MB. Caddy allowed 20 MB.
Decision: Added config/livewire.php (rule max 512 MB, upload time 15 minutes). The PHP ini is 512M and 520M for upload and post. Caddy gives 520 MB only to the path matching ^/livewire[^/]*/upload-file$, and keeps 20 MB for all other paths. The memory limit stays 512M.
Reason: A global large limit would let any page receive a large body. A file upload streams to disk.
Effect: Four numbers must stay equal (coder.php, livewire.php, Dockerfile, Caddyfile). The Caddy snippet passes caddy validate in isolation. It is not tested with FrankenPHP, and the Livewire route name is a guess for Livewire 4.

## 64. Cost and resources shown at the confirm step
Context: celaut cost gives no resources. For a package that the node does not hold, it gives no cost.
Decision: The step shows the three cost values and the admission bound of service.json (3 GiB, 10 GiB, 2 CPU) as "up to". If the cost is unknown, it says so and offers an optional field for the start bound in MU (--initial-mu). The field is empty by default.
Reason: The user can then limit the start cost when the node cannot price it. A default bound would need a price that is not known.
Effect: With no bound and no cost, the node uses its default (cost plus about 1 hour). The text warns the user.

## 65. Actions with a confirm modal
Context: Money, a stop and a restore are hard to undo.
Decision: Launch, stop, force stop, add balance and restore original are Filament actions with requiresConfirmation and a text that names the effect. Add balance has an amount field. Reload now has no modal (the watcher checks the code first). Limits and keys use buttons, with no modal. The auto reload switch applies at once and is audited (coder.recarga_auto).
Reason: A modal is for what cannot be undone. A reload is safe by design.
Effect: Each action is testable by name with callAction.

## 66. Error handling: one wrapper
Context: The node can fail, time out or refuse. A page crash would hide the controls.
Decision: Every action runs in `protegido`. CoderException and NodoException become a red notification with the message. Any other error is reported to the log and shows a short generic notice. The view data reads the state and the balance with `seguro`, with a fallback.
Reason: The page must stay usable when the node is down.
Effect: A page view makes no node call. It reads stored values only (see entry 50).

## 67. Polling only while launching
Context: A launch runs minutes in the queue. Constant polling can erase text that the user types in a field that is not sent yet.
Decision: The page polls every 5 s only in the state "launching". In that state the launch form is hidden.
Reason: No typed text is lost. The user still sees the end of the launch.
Effect: In other states, the user must reload the page to see a change of the watcher or of the balance. The action buttons refresh the page.

## 68. Connect block text
Context: The page does not know the node name of this instance.
Decision: The command uses the placeholder <instancia-de-esta-app>. The listen port is the same as the slot port (2222). The ssh config has HostKeyAlias nutrition-coder, because the host key stays the same across launches and the address is always 127.0.0.1. The page warns that each tunnel connection costs MU.
Reason: Without an alias, a second tunnel on the same port would raise a host key warning. The cost note comes from the node docs (NETWORKS and USAGE).
Effect: The user replaces one placeholder.

## 69. Confirm mode for an unreadable spec
Context: The default setting refuses a service when the spec cannot be read (entry 28).
Decision: The page follows the setting. With "rechazar" it shows the reason and no launch button. With "confirmar" it shows a box that the user must tick, and passes that choice to the job.
Reason: The page must not be a way around the setting.
Effect: With the default setting, no launch works until a spec reader exists. This is the known open issue of the earlier steps.

## 70. One parent share with the tag project
Context: The Coder and nutrition must see the same code tree. The node offers shares in two forms: a parent share and a network share.
Decision: Nutrition exports one parent share at /opt/nutrition/project with the tag "project". The Coder mounts it as a guest.
Rejected options: A network share (see entry 71). One share for each directory (it needs more tags and more checks).
Reason: A parent share needs no third service and no network. The tag, not the path, identifies the share. The Coder can mount it at any path.
Effect: The Coder must be a direct child of nutrition on the same node. The share belongs to nutrition (see entry 85).

## 71. No network share
Context: A network share can outlive both services and can join services that are not parent and child.
Decision: The feature does not use a network share.
Rejected options: A network share between nutrition and the Coder.
Reason: A network share needs an extra owner. It has more states, and a failure there is hard to see. The parent share has one owner and one life.
Effect: The code is lost when nutrition stops (see entry 85). The Coder keeps its work in git.

## 72. No control API between the Coder and nutrition
Context: The Coder must tell nutrition when to reload.
Decision: The Coder sends nothing. Nutrition polls the share (entries 10 and 14).
Rejected options: A control API on nutrition. Request files that the Coder writes in the share.
Reason: A control API is an attack surface that the Coder could abuse. A request file is a second control path that the Coder writes and root reads. A poll needs no trust in the Coder.
Effect: A reload starts up to 5 seconds plus the debounce after the last edit. Any editor works, with no special tool.

## 73. Auto reload is ON by default
Context: The reload checks the code before it acts, and it can roll back.
Decision: A missing flag file means ON. The user can turn it OFF in the panel. Then the watcher waits for "Reload now".
Reason: The aim is to edit while the server runs. With ON, an edit is live at once. With OFF, the user keeps full control.
Effect: A bad edit can start a reload. The checks and the rollback limit the damage (entries 15 and 18).

## 74. A last good snapshot and an automatic rollback
Context: A change can pass the syntax and boot checks and still fail at run time.
Decision: After each good reload the watcher saves a small tar of the code directories. If the health check fails, the watcher restores it.
Reason: The user can then use the app after a bad edit. The Coder is not always a person who can fix the code at once.
Effect: One snapshot only. The snapshot has no vendor. A bad change to vendor is not rolled back. Use "Restore original" for that case.

## 75. SSH is the only access path
Context: The user needs a shell, an editor and a file view in the Coder.
Decision: Nutrition forwards SSH bytes on port 2222. The user reaches it with `nodo tunnel`.
Rejected options: An HTTP proxy to a web editor. A proxy for the chat. Both need nutrition to parse or to serve the protocol of the Coder.
Reason: SSH carries every tool: a shell, VS Code Remote-SSH, git and port forwarding. The forwarder pipes bytes and never parses them.
Effect: The slot has the tag ssh. The Coder must follow the contract (entries 32 and 79).

## 76. The service hash is free and not fixed
Context: The author of a Coder service can publish new versions at any time.
Decision: The panel accepts any hash or an uploaded package. No hash is in the code or in the config.
Rejected options: A fixed hash in config. A list of allowed hashes.
Reason: A fixed hash would need a new release of nutrition for each Coder version. The user takes the risk, and the confirm step and the funding controls limit it.
Effect: Nutrition cannot trust the service. The contract is the only link (entry 79). See also entry 28 for the spec check.

## 77. Funding controls and their defaults
Context: A Coder spends MU that nutrition owns. A Coder with no limit can drain nutrition.
Decision: Three controls run in this order: total cap, percentage of the joint balance, minimum life of nutrition. The defaults are: cap of 24 hours at the current price with a margin, 20 percent, 48 hours.
Reason: The cap limits the total loss. The percentage limits the loss at one time. The minimum life keeps nutrition alive. The order is from the widest limit to the most specific one.
Effect: The rules are in Saldo::decidir (entries 41 to 45). The user can change the three values in the panel.

## 78. Rules for SSH keys
Context: The text in the panel becomes authorized_keys in the Coder VM.
Decision: Only five key types are valid. No key options are valid. The maximum is 20 keys. The file is written by rename.
Reason: A key option such as `command=` can run a command or open a port. A strict list closes this risk. A rename never gives a half file.
Effect: See entry 33 for the exact rules. The Coder reads the file at each login, so a change needs no relaunch.

## 79. The host key is made once and stays the same
Context: A new host key at each launch shows a warning to the user and breaks `known_hosts`.
Decision: Nutrition makes the host key at the first launch. It stores the key in /var/lib/nutrition/secretos and passes it in CODER_SSH_HOST_KEY.
Reason: The address is always 127.0.0.1 through the tunnel, so the key is the only identity check.
Effect: The key is stored in the nutrition VM, so it stays when nutrition restarts only if the disk stays (see TODO.md). The key appears in the argument list of the celaut process for a short time. It allows host impersonation only.

## 80. The Coder is launched with a reusable configuration
Context: The node reuses an idle instance with the same service and the same configuration.
Decision: The launch passes the host key as the only environment variable. It never uses --new.
Reason: A stable configuration lets the node reuse an instance, and the lease stays in the pool until nutrition stops it.
Effect: Nutrition must not release the lease of the Coder. The node needs a leased lease for metrics, deposit and tunnel.

## 81. The stop action does not trust the node
Context: Nothing cascades when nutrition stops. The node does not stop the children.
Decision: The panel stop action closes the SSH target first and then asks the node to stop. See entry 37.
Reason: A user can then stop the Coder before the shutdown of nutrition.
Effect: A shutdown of nutrition that has no stop leaves an orphan Coder. A fix is in TODO.md (the notice declaration).

## 82. Documentation language
Context: The project text is Spanish. The Coder docs, comments and commits need a clear style for a reader with a different native language.
Decision: New documentation, code comments and commit messages for this feature use ASD-STE100 English. UI text stays Spanish.
Reason: The panel is for Spanish speakers. The docs and the code are for developers and for the authors of other services.
Effect: Existing Spanish comments in other files are not changed.

## 83. Single household
Context: The panel has one household, and Hogar::unico() returns it.
Decision: The Coder page has no household picker and no household field.
Reason: A picker would add code for a case that does not exist.
Effect: The Coder state is global for the database (see entry 30).

## 84. Burn rate measured by samples, ledger method kept for later
Context: Entry 46 explains the choice. The verification found an exact ledger method.
Decision: Keep the samples. Record the ledger method in TODO.md as a later improvement.
Reason: The samples need no unverified output. The ledger method needs a live node to check the JSON.
Effect: The estimate of the hours left can be wrong when the samples are few. The controls send nothing in that case.

## 85. The share is not durable
Context: The node owns the share through the nutrition instance. It makes the share new at each start.
Decision: The docs say that nutrition holds no copy of the live tree. The Coder must keep its work in git. Nutrition makes no automatic backup of the share.
Rejected options: A periodic copy of the share to the database. It would store code of unknown size and trust.
Reason: Git in the Coder VM is the standard way to keep code. The snapshot of entry 74 is for rollback only, not for backup.
Effect: A stop of nutrition loses the edits that are not pushed. The README of this directory says so.
