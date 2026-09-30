# wilkques/ssh

PHP 用的 SSH local port-forward tunnel、遠端指令執行、SFTP 檔案傳輸套件。

三個各自獨立、可單獨使用的元件：

- **`Tunnel`** — `ssh -L` local port forward（例如：連只白名單跳板機來源的資料庫）。
- **`Exec`** — 透過 `ssh` 在遠端主機執行單一指令。
- **`Sftp`** — 用真正的 `sftp` CLI batch 模式做 `put`/`get`/`nlist`。

**零 Composer 依賴。** 三個元件都是直接 shell 出去呼叫系統的 `ssh`/`sftp`——原因見下方[與 phpseclib/phpseclib 的關係](#與-phpseclibphpseclib-的關係)。

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

參數順序比照 phpseclib 的 `SFTP::put($remote, $local)` / `SFTP::get($remote, $local)`——遠端路徑在前。用的是真正的 `sftp` binary batch 模式（真的是 SFTP 子系統，不是 `scp`）。結束碼非 0 會丟 `Wilkques\Ssh\Exceptions\SftpException`（含 stderr）。

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

## 與 phpseclib/phpseclib 的關係

`wilkques/ssh` **完全沒有** runtime 或 Composer 上對 `phpseclib/phpseclib` 的依賴——沒裝、沒 require、也沒 suggest。

`phpseclib/phpseclib` 純粹被拿來當 **API 設計參考**：`Exec::exec()`、`Sftp::put()/get()/nlist()` 刻意比照 phpseclib 自己 `SSH2`/`SFTP` 的方法名稱跟參數順序，讓熟悉 phpseclib 的人一看就會用——但底層實作是 shell 出去呼叫系統的 `ssh`/`sftp`，完全沒碰 phpseclib 的程式碼。

**範圍說明清楚一點：** phpseclib 是一整套 MIT 授權、純 PHP 實作的加密/PKI 工具箱——SSH-2、SFTP、X.509、任意精度整數運算函式庫、Ed25519/Ed449/Curve25519/Curve449、ECDSA/ECDH（支援 66 條曲線）、RSA（符合 PKCS#1 v2.2）、DSA/DH、DES/3DES/RC4/Rijndael/AES/Blowfish/Twofish/Salsa20/ChaCha20、GCM/Poly1305 等等。`wilkques/ssh` 只重疊到其中很小一塊——跑遠端指令、傳檔案——證書、金鑰產生、或 phpseclib 其他任何加密原語都完全沒碰。如果你需要那些東西，該用的還是 phpseclib，跟下面講的東西無關。

- **`Tunnel`會存在，是因為 phpseclib 完全沒有 local port-forward 的能力。** 這點已經查證過（直接看已安裝的 `phpseclib4\Net\SSH2` 原始碼，沒有 `direct-tcpip` channel 的實作），加上長年掛著沒解決的上游 issue [phpseclib/phpseclib#261](https://github.com/phpseclib/phpseclib/issues/261)。這不是依賴偏好的問題——這個能力目前沒有純 PHP 的替代方案。
- **`Exec`/`Sftp` 也選擇 shell out，即使 phpseclib 其實做得到這兩件事**，換來的是：整個套件用同一套實作策略、同一個 PHP 版本下限（`>=5.3`，phpseclib `^4.0` 自己要求 `>=8.1`）、以及零 Composer 依賴。代價是：`wilkques/ssh` 需要系統真的有 `ssh`/`sftp` 這兩支 binary 在 `PATH` 裡，不像 phpseclib 是純 PHP、自包含的實作。

如果你的環境完全不能 shell out（例如 `proc_open`被禁用），或是真的需要純 PHP 實作，該用的是 `phpseclib/phpseclib`，不是這個套件。

## License

MIT
