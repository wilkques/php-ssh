# wilkques/ssh

SSH local port-forward tunneling, remote command execution, and SFTP file transfer for PHP.

Three independent components, each usable on its own:

- **`Tunnel`** — `ssh -L` local port forwarding (e.g. reach a database that only whitelists connections from a jump box).
- **`Exec`** — run a single command on a remote host over `ssh`.
- **`Sftp`** — `put`/`get`/`nlist` over the real `sftp` CLI in batch mode.

**Zero Composer dependencies.** Every component shells out to the system `ssh`/`sftp` binaries.

## Requirements

- PHP >= 5.3
- The `ssh` binary in `PATH` (all three components need it; `Sftp` also needs `sftp`)
- `proc_open` must not be disabled

## Install

```bash
composer require wilkques/ssh
```

## `Tunnel`

```php
use Wilkques\Ssh\Tunnel;

$tunnel = new Tunnel();

$tunnel->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa'); // or ->setPassword('...') for non-interactive password auth

$tunnel->start(33061, '10.10.2.105', 3306, 30); // localPort, remoteHost, remotePort, timeoutSeconds

// ... use 127.0.0.1:33061 as if it were 10.10.2.105:3306 directly ...

$tunnel->stop(); // always stop when done — __destruct() also calls stop() as a safety net
```

`start()`/`stop()` throw `Wilkques\Ssh\Exceptions\TunnelException` on failure (process failed to launch, exited early, or the port never became ready within the timeout).

## `Exec`

```php
use Wilkques\Ssh\Exec;

$exec = new Exec();

$exec->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$output = $exec->exec('tail -n 100 /var/log/nginx/access.log');
```

Each `exec()` call is one `ssh` invocation — there is no persistent "connected session" object, consistent with how the `ssh` CLI itself works. For multiple commands in one round trip, join them with `&&` in a single `commandLine`. Throws `Wilkques\Ssh\Exceptions\ExecException` (including the remote stderr) on a non-zero exit code.

## `Sftp`

```php
use Wilkques\Ssh\Sftp;

$sftp = new Sftp();

$sftp->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$sftp->put('/remote/path/backup.sql.gz', '/local/path/backup.sql.gz');
$sftp->get('/remote/path/access.log.gz', '/local/path/access.log.gz');
$files = $sftp->nlist('/var/log/nginx');
```

Remote path comes first in `put()`/`get()`. Uses the real `sftp` binary in batch mode (genuinely the SFTP subsystem, not `scp`). Throws `Wilkques\Ssh\Exceptions\SftpException` (including stderr) on a non-zero exit.

**Known caveat:** `nlist()`'s output parsing targets standard OpenSSH `sftp -q -b` batch output; unusual `sftp` builds may format `ls` output differently.

## Non-interactive password auth

Call `setPassword($password)` on any of the three components to authenticate without a key. This writes a temporary `SSH_ASKPASS` helper script and points the relevant environment variables at it for the duration of the call, then cleans up — the same technique commonly used for scripted `ssh`/`scp` automation. Without a password set, the underlying process inherits the caller's stdin/stdout/stderr so the real binary can prompt interactively or use key auth directly.

## Exceptions

All exceptions extend `Wilkques\Ssh\Exceptions\SshException` (itself a `\RuntimeException`), so you can catch broadly or narrowly:

```php
try {
    $tunnel->start(33061, '10.10.2.105', 3306, 30);
} catch (\Wilkques\Ssh\Exceptions\TunnelException $e) {
    // ...
} catch (\Wilkques\Ssh\Exceptions\SshException $e) {
    // catches Tunnel/Exec/Sftp exceptions alike
}
```

## License

MIT
