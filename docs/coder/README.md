# Coder

The Coder feature lets nutrition start another Celaut service as a child.
That child is the Coder. It is an editor service, for example Claude Code or a VS Code server.
It changes the Laravel code while nutrition runs. No repack is necessary.

Only nutrition exists today. The Coder service itself is out of scope.
The requirements for a Coder service are in [contract.md](contract.md).
The design decisions are in [decisions.md](decisions.md).

## The three copies of the code

The code exists in three places. Do not confuse them.

| Copy | Path | Purpose |
|------|------|---------|
| Original in the image | /opt/nutrition/project | The node reads it to seed the share. The share hides it at run time. |
| Live tree | /opt/nutrition/project (the share) | The Coder edits it. Nutrition runs the app from `admin/` in it. |
| Project tar | /opt/nutrition/project.orig.tar.gz | The pristine copy. The restore action reads it. |

The share holds the whole repository: `admin/` (with `vendor`), `agente/`, `docker/`, `.service/`, `dependencies/` and `docs/`.
The node makes the share from the image of nutrition when nutrition starts.
The tar is outside the share, so the mount does not hide it.

Runtime data is not in the share. It stays in /var/lib/nutrition.
The scripts that root runs (start.sh, the watcher, the forwarder) are also outside the share.
A Coder cannot change them. A change to these scripts needs a repack.

## How reload works

Nutrition watches the share by itself. The Coder sends no request.
The script docker/servicio/recarga.sh runs as a loop and polls the share every 5 seconds.

It watches these paths: `admin/app`, `admin/config`, `admin/routes`, `admin/resources`, `admin/database`, `admin/bootstrap/app.php` and `admin/composer.lock`.

When a change appears, the watcher does these steps:

1. It waits a short time for more changes (debounce).
2. It runs `php -l` on the changed PHP files.
3. It checks that the app boots (`php artisan route:list`).
4. It runs `optimize:clear`.
5. It restarts FrankenPHP and the queue workers.
6. It checks that the app answers HTTP 200 on `/up`.

If a check fails, the watcher does not reload. The old code stays in memory.
The error goes to the log and to the panel.

After each good reload, the watcher saves a snapshot of the code directories (no `vendor`).
If the health check fails after a reload, the watcher restores the last snapshot and reports it.

A queue job that runs keeps the old code until it ends.

### Options and files

The panel and the watcher talk through small files in /var/lib/nutrition/coder.

| File | Writer | Meaning |
|------|--------|---------|
| auto-recarga | panel | Auto reload. A missing file means ON. |
| recargar-ahora | panel | Flag. Start one reload now. |
| restaurar-original | panel | Flag. Restore the original, then reload. |
| recarga.json | watcher | Status for the panel. |

Auto reload is ON by default.
When it is OFF, the watcher ignores changes until the user presses "Recargar ahora".

## Panel page

The page is "Coder" in the group Ajustes. The UI text is Spanish.
It has these parts:

- A field for a service hash or an upload of a `.celaut.bee` file.
- The estimated cost and a confirm step before the launch.
- The state: none, launching, running or error.
- The balance and the hours left of the Coder and of nutrition.
- The buttons Add balance, Stop, Reload now and Restore original.
- The switch Auto reload.
- The three funding limits.
- A text area for SSH public keys.
- The state of the live code against the original.
- The connect block and the last reload result.

## How to connect

The end user reaches the Coder through nutrition on port 2222.
A small forwarder (docker/servicio/ssh-proxy.py) pipes the bytes to the Coder.
Nutrition never reads the SSH bytes.

1. Add your public key in the panel page "Coder". Save.
2. Launch the Coder in the panel.
3. Open a tunnel on your computer:

```
nodo tunnel <nutrition instance> 2222 --listen 2222
```

4. Connect:

```
ssh -p 2222 coder@127.0.0.1
```

For VS Code, use the extension Remote-SSH.
Add this block to `~/.ssh/config`:

```
Host nutrition-coder
    HostName 127.0.0.1
    Port 2222
    User coder
    HostKeyAlias nutrition-coder
```

Then open the host `nutrition-coder` in VS Code.
Each tunnel connection costs MU, and nutrition pays it. VS Code opens several connections.

The forwarder closes a connection at once if there is no target.
When the Coder stops, nutrition removes the target file and closes the open pipes.

## How to restore

Press "Restaurar original" in the panel. The panel creates a flag file.
The watcher extracts the project tar over the share, removes the extra files in the watched paths and in `admin/vendor`, then reloads.
Files in `docs`, `agente` and `docker` are replaced, but extra files there stay.

## How funding works

Nutrition pays for the Coder. The panel does not send MU without checks.
Before each transfer, the code applies three controls in this order:

1. Total cap. The MU sent during this life plus the current balance must not pass the cap.
2. Percentage limit. After the transfer, the Coder balance must not pass a percentage of the joint balance. The default is 20 percent.
3. Minimum life of nutrition. After the transfer, nutrition must keep at least X hours of life. The default is 48 hours.

The default cap is 24 hours of Coder maintenance at the current price, with a margin of 25 percent.
The user can raise the cap, or press "Add balance" with an amount.

The life of nutrition is its balance divided by its burn rate.
The code measures the burn rate from samples of the balance.
If the rate is unknown, nothing is sent. Four samples over 600 seconds are the minimum.

Every refusal gets an audit row with the reason. The panel shows the last refusal.
The command `nutrition:vigilar-coder --saldo` runs every 300 seconds.
It tops up the Coder to a target of 12 hours when the balance is under half of that target.
It sends only the amount that the three controls allow.

## Limits

- One Coder at a time for each shared database.
- The Coder is always a direct child of nutrition on the same node.
- With the default setting, no Coder launches. The node CLI cannot read the spec of a service. See decisions 28 and 69.
- The upload limit for a package is 512 MB.
- The forwarder allows 16 open pipes, and it closes a pipe after 4 hours with no traffic.
- The disk of nutrition is 6 GiB. The value is a guess. Measure it after the first pack.
- The reload does not check the logic of the code. It checks syntax and boot only.

## What is lost when nutrition stops

The share is not durable. The node owns it through the nutrition instance.

- When nutrition stops, all edits in the share are lost.
- The Coder keeps running with a mount that fails. A new Coder cannot mount the old share.
- The node does not stop the children of nutrition. The Coder runs as an orphan until its MU end.
- When nutrition starts again, the share is new. It holds the code of the image.

Keep the work in git in the Coder VM. Push it to a remote often.
The Coder must stop before nutrition stops. Use the "Detener" button.
