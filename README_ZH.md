# wilkques/ssh

PHP 用的 SSH local port-forward tunnel、遠端指令執行、SFTP 檔案傳輸套件。

三個各自獨立、可單獨使用的元件：

- **`Tunnel`** — `ssh -L` local port forward（例如：連只白名單跳板機來源的資料庫）。
- **`Exec`** — 透過 `ssh` 在遠端主機執行單一指令。
- **`Sftp`** — 用真正的 `sftp` CLI batch 模式做 `put`/`get`/`nlist`。

**零 Composer 依賴。** 三個元件都是直接 shell 出去呼叫系統的 `ssh`/`sftp`。

## 環境需求

- PHP >= 5.3
- `PATH` 裡要有 `ssh`（三個元件都需要；`Sftp` 另外需要 `sftp`）
- `proc_open` 不能被禁用

## 安裝

```bash
composer require wilkques/ssh
```

## `Tunnel`

```php
use Wilkques\Ssh\Tunnel;

$tunnel = new Tunnel();

$tunnel->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa'); // 或用 ->setPassword('...') 做非互動式密碼登入

$tunnel->start(33061, '10.10.2.105', 3306, 30); // localPort, remoteHost, remotePort, timeoutSeconds

// ... 接下來打 127.0.0.1:33061 就等於直接打 10.10.2.105:3306 ...

$tunnel->stop(); // 用完一定要 stop()——__destruct() 也會保底呼叫一次
```

`start()`/`stop()` 失敗時會丟 `Wilkques\Ssh\Exceptions\TunnelException`（程序啟動失敗、提早結束、或逾時 port 都還沒通）。

## `Exec`

```php
use Wilkques\Ssh\Exec;

$exec = new Exec();

$exec->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$output = $exec->exec('tail -n 100 /var/log/nginx/access.log');
```

每次 `exec()` 就是一次獨立的 `ssh` 呼叫，沒有「持續連線的 session」物件，跟 `ssh` CLI 本身的行為一致；要一次跑多個指令就自己在 `commandLine` 裡用 `&&` 串起來。結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\ExecException`（含遠端 stderr）。

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

`put()`/`get()` 都是遠端路徑在前。用的是真正的 `sftp` binary batch 模式（真的是 SFTP 子系統，不是 `scp`）。結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\SftpException`（含 stderr）。

**已知限制：** `nlist()` 的輸出解析是針對標準 OpenSSH `sftp -q -b` 的 batch 輸出格式；少見的 `sftp` 版本輸出格式可能不同。

## 非互動式密碼登入

三個元件都可以呼叫 `setPassword($password)` 做免金鑰登入——做法是寫一個暫存的 `SSH_ASKPASS` 腳本，呼叫期間把相關環境變數指過去，結束後清掉，這是常見的 `ssh`/`scp` 自動化技巧。沒設密碼時，底層程序會直接沿用呼叫端的 stdin/stdout/stderr，讓真正的 binary 可以互動式提示輸入或直接用金鑰登入。

## 例外處理

所有例外都繼承自 `Wilkques\Ssh\Exceptions\SshException`（本身是 `\RuntimeException`），可以廣抓也可以精準抓：

```php
try {
    $tunnel->start(33061, '10.10.2.105', 3306, 30);
} catch (\Wilkques\Ssh\Exceptions\TunnelException $e) {
    // ...
} catch (\Wilkques\Ssh\Exceptions\SshException $e) {
    // 同時接住 Tunnel/Exec/Sftp 的例外
}
```

## License

MIT
