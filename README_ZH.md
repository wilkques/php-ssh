# wilkques/ssh

PHP 用的 SSH local port-forward tunnel、遠端指令執行、SFTP/SCP 檔案傳輸套件。

四個各自獨立、可單獨使用的元件：

- **`Tunnel`** — `ssh -L` local port forward（例如：連只白名單跳板機來源的資料庫）。
- **`Exec`** — 透過 `ssh` 在遠端主機執行單一指令。
- **`Sftp`** — 用真正的 `sftp` CLI batch 模式做 `put`/`get`/`nlist`/`mkdir`/`rmdir`/`rename`/`chmod`/`delete`/`exists`。
- **`Scp`** — 用真正的 `scp` binary 做 `put`/`get`（可選遞迴）。

**零 Composer 依賴。** 四個元件都是直接 shell 出去呼叫系統的 `ssh`/`sftp`/`scp`。

## 環境需求

- PHP >= 5.3
- `PATH` 裡要有 `ssh`（四個元件都需要；`Sftp` 另外需要 `sftp`，`Scp` 另外需要 `scp`）
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
$files = $sftp->nlist('/var/log/nginx');     // 回傳檔名陣列（純檔名），例如 ['access.log', 'error.log']

$sftp->mkdir('/remote/path/new-dir');
$sftp->rmdir('/remote/path/empty-dir');
$sftp->rename('/remote/old.log', '/remote/new.log');
$sftp->chmod('/remote/path/backup.sql.gz', 0644);   // 可傳 8 進位整數字面值或字串 '644'
$sftp->delete('/remote/path/old-backup.sql.gz');
$exists = $sftp->exists('/remote/path/backup.sql.gz');   // bool
```

`put()`/`get()`/`chmod()` 都是遠端路徑在前。用的是真正的 `sftp` binary batch 模式（真的是 SFTP 子系統，不是 `scp`）。結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\SftpException`（含 stderr）——`exists()` 例外，路徑不存在時回傳 `false`，不丟例外。

**已知限制：** `nlist()` 的輸出解析是針對標準 OpenSSH `sftp -q -b` 的 batch 輸出格式；少見的 `sftp` 版本輸出格式可能不同。

## `Scp`

```php
use Wilkques\Ssh\Scp;

$scp = new Scp();

$scp->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$scp->put('/remote/path/backup.sql.gz', '/local/path/backup.sql.gz');
$scp->get('/remote/path/access.log.gz', '/local/path/access.log.gz');

$scp->put('/remote/path/dir', '/local/path/dir', true);   // 第三個參數：遞迴（-r）
$scp->get('/remote/path/dir', '/local/path/dir', true);
```

跟 `Sftp` 一樣是 `put($remote, $local)` / `get($remote, $local)` 的參數順序，只是底層走的是真正的 `scp` binary，不是 `sftp`——適合目標主機只開 `scp`（舊協定）沒開 SFTP 子系統的情況。第三個參數傳 `true` 可以整個資料夾遞迴複製（`-r`）。結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\ScpException`（含 stderr）。

## 連線選項

四個元件（`Tunnel`、`Exec`、`Sftp`、`Scp`）共用同一套連線選項設定方法：

```php
$exec->setPort(2222)                              // 非預設的 SSH port
    ->setStrictHostKeyChecking('yes')             // 預設 'accept-new'；'yes' 做嚴格驗證，'no' 完全不驗證（不建議）
    ->setKnownHostsFile('/path/to/known_hosts')   // 搭配 setStrictHostKeyChecking('yes') 使用
    ->setProxyJump('jumpuser@jumphost:2200')      // -J，透過跳板主機再連
    ->setCompression()                            // -C
    ->setTimeout(10)                              // -o ConnectTimeout=<seconds>，預設 30
    ->addOption('ServerAliveInterval', '60');     // 其他沒特別包裝的 -o key=value 選項
```

**連線多工**（`ControlMaster`/`ControlPath`/`ControlPersist`）讓同一個物件重複呼叫時重用已建立的連線，不用每次都重新握手：

```php
$exec->setMultiplexing(true, '10m');   // 開啟，共用連線在最後一次使用後再維持 10 分鐘

$exec->exec('cmd one');   // 建立共用連線
$exec->exec('cmd two');   // 重用它，不會重新握手

$exec->closeMultiplexedConnection();   // 用完主動關閉
```

已用真的本機 `sshd` 驗證過可以運作。**限制：** Linux/macOS 上穩定可用；Windows 上能不能用，取決於那台機器裝的 OpenSSH 版本是否真的支援 `ControlMaster`/Unix domain socket，不是每台 Windows 都保證有。

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
    // 同時接住 Tunnel/Exec/Sftp/Scp 的例外
}
```

## License

MIT
