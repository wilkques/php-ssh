# wilkques/ssh

[English](README.md) | [繁體中文](README_ZH.md)

SSH local port-forward tunneling, remote command execution, and SFTP/SCP file transfer for PHP.

Four independent components, each usable on its own:

- **`Tunnel`** — `ssh -L` local port forwarding (e.g. reach a database that only whitelists connections from a jump box).
- **`Exec`** — run a single command on a remote host over `ssh`.
- **`Sftp`** — `put`/`get`/`nlist`/`mkdir`/`rmdir`/`rename`/`chmod`/`delete`/`exists`/`stat`/`realpath`, with progress reporting, recursive transfers, and resume, over a real SFTPv3 protocol channel.
- **`Scp`** — `put`/`get` (optionally recursive) with `scp`'s own directory-target semantics, over the same channel as `Sftp` by default (or the real `scp` binary via `setLegacy(true)`).

**Zero Composer dependencies.** `Tunnel`/`Exec` shell out to the system `ssh` binary; `Sftp`/`Scp` speak the SFTP protocol directly over an `ssh` subprocess's pipes — no protocol library, just PHP talking the wire format itself (falling back to the real `scp` binary only in `Scp::setLegacy(true)` mode).

## Requirements

- PHP >= 5.3
- The `ssh` binary in `PATH` (all four components need it; `Scp` also needs `scp`, but only in `setLegacy(true)` mode)
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

$info = $sftp->stat('/remote/path/backup.sql.gz');       // array: size/uid/gid/permissions/atime/mtime, whichever the server reports
$resolved = $sftp->realpath('~/backup.sql.gz');          // ask the server to resolve `~`, `..`, relative paths, etc.

// put()/get() take an optional 3rd `$options` array — additive, existing 2-arg calls above still work as-is
$sftp->put('/remote/path/dir', '/local/path/dir', array('recursive' => true));
$sftp->get('/remote/path/dir', '/local/path/dir', array('recursive' => true));
$sftp->put('/remote/path/big.iso', '/local/path/big.iso', array('resume' => true)); // continue from the remote file's current size
$sftp->get('/remote/path/big.iso', '/local/path/big.iso', array('resume' => true)); // continue from the local file's current size
```

Remote path comes first in `put()`/`get()`/`chmod()`/`stat()`. Speaks the SFTPv3 protocol directly over a persistent `ssh -s host sftp` subprocess (see [Progress reporting](#progress-reporting) below for why) — needs only the `ssh` binary, not `sftp`. Throws `Wilkques\Ssh\Exceptions\SftpException` on failure, carrying the server's `SSH_FX_*` status code (where there is one) as the exception code — except `exists()`, which returns `false` specifically for "no such file" and still throws for anything else (a permission error, for instance, is not the same thing as "doesn't exist").

`recursive`/`resume` are deliberately naive: a non-recursive `put()`/`get()` against a directory throws rather than silently doing the wrong thing, and `resume` just continues from a byte offset with no content verification — the same approach `sftp`'s own `reput`/`reget` take.

The underlying `ssh` subprocess is opened lazily on first use and kept open across calls on the same object (reconnecting it every time would defeat the point of a persistent channel). Call `$sftp->disconnect()` to close it explicitly when done — `__destruct()` also calls it as a safety net, same as `Tunnel::stop()`.

## `Scp`

```php
use Wilkques\Ssh\Scp;

$scp = new Scp();

$scp->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$scp->put('/remote/path/backup.sql.gz', '/local/path/backup.sql.gz');
$scp->get('/remote/path/access.log.gz', '/local/path/access.log.gz');

$scp->put('/remote/path/dir', '/local/path/dir', true);   // 3rd arg: recursive
$scp->get('/remote/path/dir', '/local/path/dir', true);

// an existing target directory means "copy into it", same as the real scp binary:
$scp->put('/remote/existing-dir', '/local/file.txt');     // uploads to /remote/existing-dir/file.txt
```

Same `put($remote, $local, $recursive = false)` / `get($remote, $local, $recursive = false)` shape as before. By default `Scp` shares the exact same SFTP protocol channel as `Sftp` — one engine, two facades, mirroring the relationship upstream `scp` and `sftp` have had since OpenSSH 9.0, when `scp` itself switched to speaking SFTP under the hood by default. Needs only the `ssh` binary, not `scp`, and gets the same [progress reporting](#progress-reporting) as `Sftp`.

Call `setLegacy(true)` to fall back to shelling out to the real `scp` binary instead (with `-O`, forcing the legacy SCP/RCP protocol) — for a server with no SFTP subsystem. `scp` needs to be in `PATH` only in this mode, and there's no progress reporting in it: combining `setProgress()` with `setLegacy(true)` throws rather than silently doing nothing.

Throws `Wilkques\Ssh\Exceptions\ScpException` on failure.

## Progress reporting

```php
$sftp->setProgress(function ($transferred, $total, $path) {
    printf("\r%s: %d%%", $path, $total > 0 ? (int) ($transferred / $total * 100) : 100);
});

$sftp->put('/remote/path/big.iso', '/local/path/big.iso');

$sftp->setProgress(null); // stop reporting
```

The same method exists on `Scp` (not in `setLegacy(true)` mode — see above). `$transferred`/`$total` are bytes the peer has actually acknowledged, not merely handed to the local pipe — a write's progress only advances once its acknowledgement comes back (contrast this with, say, phpseclib's SFTP `put()`, whose progress callback fires before any acknowledgement is read at all, up to 32MB ahead of what the server has actually confirmed at its default queue depth). Reporting is throttled by default to at most once per 200ms or 1% of progress, whichever comes first — tune it with a second argument: `setProgress($callback, array('interval' => 0.1, 'minDelta' => 0.005))`. The final report at completion always fires regardless of throttling.

This is also why this package moved `Sftp`/`Scp` off the `sftp`/`scp` CLI binaries and onto a channel speaking the SFTP protocol directly: those binaries' own progress meters are unconditionally disabled in non-interactive/batch use, and even interactively only draw when stdout is a real foreground terminal — something PHP can't guarantee, especially on Windows.

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

Call `setPassword($password)` on any of the four components to authenticate without a key. This writes a temporary `SSH_ASKPASS` helper script and points the relevant environment variables at it for the duration of the call, then cleans up — the same technique commonly used for scripted `ssh`/`scp` automation. Without a password set, the underlying process inherits the caller's stdin/stdout/stderr so the real binary can prompt interactively or use key auth directly.

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
