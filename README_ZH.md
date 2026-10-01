# wilkques/ssh

[English](README.md) | [繁體中文](README_ZH.md)

PHP 用的 SSH local port-forward tunnel、遠端指令執行、SFTP/SCP 檔案傳輸套件。

四個各自獨立、可單獨使用的元件：

- **`Tunnel`** — `ssh -L` local port forward（例如：連只白名單跳板機來源的資料庫）。
- **`Exec`** — 透過 `ssh` 在遠端主機執行單一指令。
- **`Sftp`** — `put`/`get`/`nlist`/`mkdir`/`rmdir`/`rename`/`chmod`/`delete`/`exists`/`stat`/`realpath`，外加進度回呼、遞迴傳輸、續傳，底層是真正的 SFTPv3 協定管道。
- **`Scp`** — `put`/`get`（可選遞迴），保留 `scp` 自己的「目標是目錄就複製進去」語意，預設跟 `Sftp` 共用同一條管道（或用 `setLegacy(true)` 退回真正的 `scp` binary）。

**零 Composer 依賴。** `Tunnel`/`Exec` 直接 shell 出去呼叫系統的 `ssh`；`Sftp`/`Scp` 則是在 `ssh` 子行程的管道上直接講 SFTP 協定——沒有用任何協定函式庫，就是 PHP 自己講 wire format（只有 `Scp::setLegacy(true)` 模式才會退回真正的 `scp` binary）。

## 環境需求

- PHP >= 5.3
- `PATH` 裡要有 `ssh`（四個元件都需要；`Scp` 另外需要 `scp`，但只有 `setLegacy(true)` 模式才用得到）
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

$info = $sftp->stat('/remote/path/backup.sql.gz');       // 陣列：size/uid/gid/permissions/atime/mtime，視伺服器實際回報的欄位而定
$resolved = $sftp->realpath('~/backup.sql.gz');          // 請伺服器解析 `~`、`..`、相對路徑等

// put()/get() 多了一個可選的第 3 個 `$options` 陣列參數——純新增，上面的 2 參數寫法照樣能用
$sftp->put('/remote/path/dir', '/local/path/dir', array('recursive' => true));
$sftp->get('/remote/path/dir', '/local/path/dir', array('recursive' => true));
$sftp->put('/remote/path/big.iso', '/local/path/big.iso', array('resume' => true)); // 從遠端檔案目前的大小接著傳
$sftp->get('/remote/path/big.iso', '/local/path/big.iso', array('resume' => true)); // 從本機檔案目前的大小接著傳
```

`put()`/`get()`/`chmod()`/`stat()` 都是遠端路徑在前。底層是在一個持續存在的 `ssh -s host sftp` 子行程上直接講 SFTPv3 協定（原因見下方的[進度回呼](#進度回呼)）——只需要 `ssh`，不需要 `sftp`。失敗會丟 `Wilkques\Ssh\Exceptions\SftpException`，伺服器有回報 `SSH_FX_*` 狀態碼的話會帶在例外的 code 裡——`exists()` 例外，只有「不存在」時才回傳 `false`，其他錯誤（例如權限不足）一律還是會丟出去，不會被誤判成「不存在」。

`recursive`/`resume` 刻意做得很單純：非遞迴的 `put()`/`get()` 對上目錄會直接丟例外，不會默默做錯事；`resume` 就只是從某個位移量接著傳，不驗證內容是否相符——跟 `sftp` 自己的 `reput`/`reget` 做法一樣。

底層的 `ssh` 子行程是第一次用到時才會惰性建立，而且同一個物件後續呼叫會一直沿用，不會每次都重連（不然持續管道就失去意義了）。用完呼叫 `$sftp->disconnect()` 主動關閉；`__destruct()` 也會保底呼叫一次，跟 `Tunnel::stop()` 一樣。

## `Scp`

```php
use Wilkques\Ssh\Scp;

$scp = new Scp();

$scp->setSshIp('10.10.2.58')
    ->setUser('deploy')
    ->setIdRsaPath('/home/me/.ssh/id_rsa');

$scp->put('/remote/path/backup.sql.gz', '/local/path/backup.sql.gz');
$scp->get('/remote/path/access.log.gz', '/local/path/access.log.gz');

$scp->put('/remote/path/dir', '/local/path/dir', true);   // 第三個參數：遞迴
$scp->get('/remote/path/dir', '/local/path/dir', true);

// 目標已經是一個目錄的話，會複製「進去」，跟真正的 scp binary 行為一致：
$scp->put('/remote/existing-dir', '/local/file.txt');     // 上傳到 /remote/existing-dir/file.txt
```

跟以前一樣是 `put($remote, $local, $recursive = false)` / `get($remote, $local, $recursive = false)` 的參數形狀。預設 `Scp` 跟 `Sftp` 共用同一條 SFTP 協定管道——一個引擎、兩個外觀，跟 OpenSSH 9.0 之後 `scp`、`sftp` 之間的關係一樣（`scp` 從那版開始預設底層也改講 SFTP）。只需要 `ssh`，不需要 `scp`，而且跟 `Sftp` 一樣有[進度回呼](#進度回呼)。

想退回真正的 `scp` binary（加上 `-O` 強制走舊版 SCP/RCP 協定）就呼叫 `setLegacy(true)`——適合目標主機沒有 SFTP 子系統的情況。只有這個模式才需要 `PATH` 裡有 `scp`，而且這個模式沒有進度回呼：`setProgress()` 跟 `setLegacy(true)` 一起用會直接丟例外，不會默默沒反應。

結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\ScpException`。

## 進度回呼

```php
$sftp->setProgress(function ($transferred, $total, $path) {
    printf("\r%s: %d%%", $path, $total > 0 ? (int) ($transferred / $total * 100) : 100);
});

$sftp->put('/remote/path/big.iso', '/local/path/big.iso');

$sftp->setProgress(null); // 取消回呼
```

`Scp` 也有一樣的方法（`setLegacy(true)` 模式除外，見上）。`$transferred`/`$total` 是對方**已經確認**的 bytes 數，不是只是丟進本機 pipe 的量——上傳的進度要等那個 WRITE 的回覆收到才會往前走（對照一下，phpseclib 的 SFTP `put()` 進度回呼是在讀任何回覆之前就先觸發，預設佇列深度下最多可以超前伺服器實際確認的進度達 32MB）。預設節流：最快每 200ms 或每 1% 進度才觸發一次，可以用第二個參數調整：`setProgress($callback, array('interval' => 0.1, 'minDelta' => 0.005))`。完成時的最後一次回報一定會觸發，不受節流限制。

這也是這個套件把 `Sftp`/`Scp` 從 `sftp`/`scp` 這兩支 CLI 換成直接講 SFTP 協定的原因：這兩支二進位檔自己的進度條在非互動／batch 模式下本來就一定被關掉，就算是互動模式也只有 stdout 是真正的前景終端機才會畫——這件事 PHP 沒辦法保證，尤其是在 Windows 上。

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

四個元件都可以呼叫 `setPassword($password)` 做免金鑰登入——做法是寫一個暫存的 `SSH_ASKPASS` 腳本，呼叫期間把相關環境變數指過去，結束後清掉，這是常見的 `ssh`/`scp` 自動化技巧。沒設密碼時，底層程序會直接沿用呼叫端的 stdin/stdout/stderr，讓真正的 binary 可以互動式提示輸入或直接用金鑰登入。

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
