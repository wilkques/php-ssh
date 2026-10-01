# wilkques/ssh

SSH local port-forward tunneling, remote command execution, and SFTP/SCP file transfer for PHP.

Four independent components, each usable on its own:

- **`Tunnel`** — `ssh -L` local port forwarding (e.g. reach a database that only whitelists connections from a jump box).
- **`Exec`** — run a single command on a remote host over `ssh`.
- **`Sftp`** — `put`/`get`/`nlist`/`mkdir`/`rmdir`/`rename`/`chmod`/`delete`/`exists` over the real `sftp` CLI in batch mode.
- **`Scp`** — `put`/`get` (optionally recursive) over the real `scp` binary.

**Zero Composer dependencies.** Every component shells out to the system `ssh`/`sftp`/`scp` binaries.

## Requirements

- PHP >= 5.3
- The `ssh` binary in `PATH` (all four components need it; `Sftp` also needs `sftp`, `Scp` also needs `scp`)
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
$files = $sftp->nlist('/var/log/nginx');     // array of filenames (basenames), e.g. ['access.log', 'error.log']

$sftp->mkdir('/remote/path/new-dir');
$sftp->rmdir('/remote/path/empty-dir');
$sftp->rename('/remote/old.log', '/remote/new.log');
$sftp->chmod('/remote/path/backup.sql.gz', 0644);   // accepts an octal int literal or a string like '644'
$sftp->delete('/remote/path/old-backup.sql.gz');
$exists = $sftp->exists('/remote/path/backup.sql.gz');   // bool
```

Remote path comes first in `put()`/`get()`/`chmod()`. Uses the real `sftp` binary in batch mode (genuinely the SFTP subsystem, not `scp`). Throws `Wilkques\Ssh\Exceptions\SftpException` (including stderr) on a non-zero exit — except `exists()`, which returns `false` instead of throwing when the path doesn't exist.

**Known caveat:** `nlist()`'s output parsing targets standard OpenSSH `sftp -q -b` batch output; unusual `sftp` builds may format `ls` output differently.

## `Scp`

```php
use Wilkques\Ssh\Scp;

$scp = new Scp();

$scp->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$scp->put('/remote/path/backup.sql.gz', '/local/path/backup.sql.gz');
$scp->get('/remote/path/access.log.gz', '/local/path/access.log.gz');

$scp->put('/remote/path/dir', '/local/path/dir', true);   // 3rd arg: recursive (-r)
$scp->get('/remote/path/dir', '/local/path/dir', true);
```

Same `put($remote, $local)` / `get($remote, $local)` shape as `Sftp`, but over the real `scp` binary instead of `sftp` — useful when a target host only has the legacy `scp` protocol enabled. Pass `true` as the third argument to copy a whole directory recursively (`-r`). Throws `Wilkques\Ssh\Exceptions\ScpException` (including stderr) on a non-zero exit.

## Connection options

Every component (`Tunnel`, `Exec`, `Sftp`, `Scp`) shares the same fluent connection-option setters:

```php
$exec->setPort(2222)                              // non-default SSH port
    ->setStrictHostKeyChecking('yes')             // default 'accept-new'; use 'yes' for strict verification, 'no' to disable entirely (not recommended)
    ->setKnownHostsFile('/path/to/known_hosts')   // pairs with setStrictHostKeyChecking('yes')
    ->setProxyJump('jumpuser@jumphost:2200')      // -J, hop through a jump host
    ->setCompression()                            // -C
    ->setTimeout(10)                              // -o ConnectTimeout=<seconds>, default 30
    ->addOption('ServerAliveInterval', '60');     // any other -o key=value this package doesn't wrap directly
```

**Connection multiplexing** (`ControlMaster`/`ControlPath`/`ControlPersist`) lets repeated calls on the same object reuse one already-established connection instead of re-handshaking every time:

```php
$exec->setMultiplexing(true, '10m');   // enabled, keep the shared connection alive for 10 minutes after the last use

$exec->exec('cmd one');   // establishes the shared connection
$exec->exec('cmd two');   // reuses it — no new handshake

$exec->closeMultiplexedConnection();   // tear it down explicitly when done
```

Verified against a real local `sshd`. **Caveat:** reliable on Linux/macOS; on Windows it depends on the installed OpenSSH build actually supporting `ControlMaster`/Unix-domain sockets, which isn't guaranteed on every Windows machine.

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
    // catches Tunnel/Exec/Sftp/Scp exceptions alike
}
```

## License

MIT
