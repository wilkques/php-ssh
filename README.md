# wilkques/ssh

SSH local port-forward tunneling, remote command execution, and SFTP file transfer for PHP.

Three independent components, each usable on its own:

- **`Tunnel`** — `ssh -L` local port forwarding (e.g. reach a database that only whitelists connections from a jump box).
- **`Exec`** — run a single command on a remote host over `ssh`.
- **`Sftp`** — `put`/`get`/`nlist` over the real `sftp` CLI in batch mode.

**Zero Composer dependencies.** Every component shells out to the system `ssh`/`sftp` binaries — see [Relationship to phpseclib/phpseclib](#relationship-to-phpseclibphpseclib) below for why.

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

Argument order matches phpseclib's `SFTP::put($remote, $local)` / `SFTP::get($remote, $local)` — remote path first. Uses the real `sftp` binary in batch mode (genuinely the SFTP subsystem, not `scp`). Throws `Wilkques\Ssh\Exceptions\SftpException` (including stderr) on a non-zero exit.

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

## Relationship to phpseclib/phpseclib

`wilkques/ssh` has **zero runtime or Composer dependency on `phpseclib/phpseclib`** — it is not installed, not required, not suggested.

`phpseclib/phpseclib` was used purely as an **API-design reference**: `Exec::exec()` and `Sftp::put()/get()/nlist()` deliberately mirror phpseclib's own `SSH2`/`SFTP` method names and argument order, so the interface is immediately familiar to anyone who's used phpseclib — but the implementation underneath shells out to the system `ssh`/`sftp` binaries; it never touches phpseclib's code.

**Scope, to be clear:** phpseclib is a full MIT-licensed pure-PHP crypto/PKI suite — SSH-2, SFTP, X.509, an arbitrary-precision integer arithmetic library, Ed25519/Ed449/Curve25519/Curve449, ECDSA/ECDH (66 curves), RSA (PKCS#1 v2.2), DSA/DH, DES/3DES/RC4/Rijndael/AES/Blowfish/Twofish/Salsa20/ChaCha20, GCM/Poly1305, and more. `wilkques/ssh` overlaps with a narrow slice of that — running a remote command and transferring files — and doesn't touch certificates, key generation, or any of phpseclib's other primitives at all. Need any of that? phpseclib is still the tool, independent of anything below.

- **`Tunnel` exists because phpseclib cannot do local port-forwarding at all.** Confirmed by source inspection of the installed `phpseclib4\Net\SSH2` (no `direct-tcpip` channel support) and the long-open upstream issue [phpseclib/phpseclib#261](https://github.com/phpseclib/phpseclib/issues/261). This isn't a matter of dependency preference — there is no pure-PHP alternative for this specific capability today.
- **`Exec`/`Sftp` shell out too, even though phpseclib *could* implement both**, in exchange for: one implementation strategy for the whole package, one PHP-version floor (`>=5.3`, vs. phpseclib `^4.0`'s own `>=8.1` requirement), and zero Composer dependencies. The tradeoff: `wilkques/ssh` requires the system to actually have `ssh`/`sftp` binaries in `PATH`, rather than being a pure-PHP/self-contained implementation.

If your environment can't shell out at all (e.g. `proc_open` disabled) or genuinely needs a pure-PHP SSH implementation, `phpseclib/phpseclib` is the right tool — not this package.

## License

MIT
