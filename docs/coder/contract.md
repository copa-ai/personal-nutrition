# Contract for a Coder service

This file lists the requirements for an author of a Coder service.
Nutrition launches any service hash that the user gives. It cannot read the spec of the service.
A service that does not follow this contract starts, but the user cannot use it.

Words in this file:

- Nutrition is the parent service.
- The Coder is the child service.
- The share is the directory that nutrition exports.

## Requirements

1. The Coder is a direct child of nutrition. The panel of nutrition launches it. Nobody else can launch it.
2. The Coder declares a shared filesystem with role `guest` and tag `project`. The tag identifies the share. The path is free. Example:

   ```json
   {"path": "/work", "role": "guest", "tag": "project", "access": "rw"}
   ```

3. The Coder runs an SSH server. The slot of the SSH server is the first api slot in `service.json`. Nutrition uses the address of the first slot. It cannot choose a slot by tag.
4. The SSH slot has the tag `ssh` in its protocol stack. Example:

   ```json
   {"port": 22, "transport": "tcp", "protocol_stack": [["ssh"]]}
   ```

5. The SSH server accepts the user `coder`. Other users are not necessary.
6. The SSH server accepts keys only. Turn off password authentication and keyboard-interactive authentication.
7. The SSH server sets `AllowTcpForwarding yes`. VS Code Remote-SSH needs it.
8. The SSH server reads the authorized keys from the file `.coder/authorized_keys` at the root of the share. Use `AuthorizedKeysFile` with the full path of the mount. Read the file at each login. The user changes keys in the panel, and the Coder does not restart.
9. The Coder reads the host key from the environment variable `CODER_SSH_HOST_KEY`. The value is the base64 text of an OpenSSH private key (ed25519). Decode it at start. Write it to a file with mode 600. Give it to `sshd` as `HostKey`. Do not make a new host key. Nutrition makes the key once and keeps it, so the host key does not change between launches.
10. The Coder keeps its own work in git. The share is lost when nutrition stops. Push to a remote often.
11. The Coder does not share a network filesystem with nutrition. The share is the only link. Nutrition does not send a request to the Coder.
12. The Coder runs tests, composer and all other tools in its own VM. Nutrition runs none of them.
13. The author packs the Coder with `celaut pack`. The Coder has its own repository and its own image.
14. The Coder declares resources inside the bound of nutrition: 3 GiB memory, 10 GiB disk and 2 CPU. A node can refuse a larger Coder.
15. The Coder must stay inside the cost limits. Nutrition pays the balance. The panel shows the cost before the launch.

## What the Coder can change

The Coder edits the files in the share. Nutrition reloads the app when these paths change:

- `admin/app`, `admin/config`, `admin/routes`, `admin/resources` and `admin/database`
- `admin/bootstrap/app.php`
- `admin/composer.lock`

Nutrition checks the syntax and the boot before it reloads.
If the code fails a check, nutrition keeps the old code and shows the error in the panel.

A change to `docker/` or `.service/` does not change the running service.
Those files need a repack.

Nutrition does not run `composer`. If the Coder changes `composer.lock`, it must also update `admin/vendor` in the share.

## What nutrition checks at launch

- The node must hold the service, or the user must upload a `.celaut.bee` file.
- Nutrition connects to the address of the first slot. It reads the banner `SSH-2.0-` for up to 180 seconds.
- If there is no banner, nutrition stops the Coder and shows an error.
- Nutrition writes the target of the forwarder only after the banner test passes.

## Access for the end user

The end user opens a tunnel to port 2222 of nutrition. Then the user connects as `coder`.
See [README.md](README.md) for the commands.
