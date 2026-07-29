<?php
declare(strict_types=1);

// 利用状況ダッシュボード(デプロイ先: /app/stats.php)【オーナー専用】
//
// エックスサーバーがドメインごとに保存するアクセスログ(<ドメイン>/log/)をPHPから直接読み、
// 5つのアプリ/ゲームのトップページ表示回数をブラウザで確認できるようにする。
// - 初回アクセス: /app/stats.php?k=<admin_key> → 専用Cookie(1年)を発行してキーをURLから消す
// - 2回目以降: Cookieだけで開ける
// - admin_key は /app/_private/config.php で管理(変更すれば即無効化できる)

require __DIR__ . '/dream-diary/api/_lib.php';

/* ---------- 認証(オーナー専用。会員の解錠Cookieでは開けない) ---------- */
$key = (string) ($_GET['k'] ?? '');
$adminKey = (string) (cfg()['admin_key'] ?? '');
if ($key !== '') {
  if (!attempt_rate_ok('stats', 10)) {
    http_response_code(429);
    exit('試行回数が多すぎます。1分ほど待ってください。');
  }
  if ($adminKey === '' || !hash_equals($adminKey, $key)) {
    http_response_code(403);
    exit('アクセスキーが違います。');
  }
  $exp = time() + 365 * 24 * 60 * 60;
  setcookie('unlock_app_admin', make_app_cookie('admin', $exp), [
    'expires' => $exp, 'path' => '/app/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
  ]);
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 302); // キーをURLに残さない
  exit;
}
if (!verify_app_cookie('admin')) {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  exit('<!DOCTYPE html><html lang="ja"><body style="font-family:sans-serif;max-width:480px;margin:3em auto;">'
     . '<h2>オーナー専用ページです</h2><p>?k=アクセスキー を付けて開いてください。</p></body></html>');
}

/* ---------- 集計対象 ---------- */
const APPS = [
  '夢日記'         => '#^/app/dream-diary/(index\.html)?$#',
  '明晰夢'         => '#^/app/lucid-dream/(index\.php|index\.html)?$#',
  'ヤコブの梯子'   => '#^/games/hashigo/(index\.html)?$#',
  '消えた踏切'     => '#^/games/fumikiri/(index\.html)?$#',
  'はざまの図書館' => '#^/games/hazama/(index\.html)?$#',
];
const DAYS_TO_SCAN = 35;   // 直近35日分のログファイルだけ読む
const DAYS_TO_SHOW = 14;   // 日別表は直近14日

/* ---------- ログ読み込み ---------- */
// Xserverの構成: /home/<ID>/<ドメイン>/public_html と並びの log/ にアクセスログが保存される
$logDir = dirname($_SERVER['DOCUMENT_ROOT']) . '/log';

// ログの実際の場所を確認するためのデバッグ表示(オーナー専用認証の内側)
if (isset($_GET['debug'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "DOCUMENT_ROOT: {$_SERVER['DOCUMENT_ROOT']}\n";
  foreach ([dirname($_SERVER['DOCUMENT_ROOT']), $logDir, dirname($_SERVER['DOCUMENT_ROOT'], 2)] as $d) {
    echo "\n== $d ==\n";
    if (!is_dir($d)) { echo "(ディレクトリなし/不可視)\n"; continue; }
    foreach ((scandir($d) ?: []) as $f) {
      if ($f === '.' || $f === '..') continue;
      $p = "$d/$f";
      echo (is_dir($p) ? '[D] ' : '    ') . $f . (is_file($p) ? ' (' . filesize($p) . 'B)' : '') . "\n";
    }
  }
  exit;
}
$files = [];
foreach (glob($logDir . '/*access*') ?: [] as $f) {
  if (is_file($f) && filemtime($f) > time() - DAYS_TO_SCAN * 86400) $files[] = $f;
}
sort($files);

function log_lines(string $file): Generator {
  $isGz = substr($file, -3) === '.gz';
  $h = $isGz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
  if (!$h) return;
  while (($line = $isGz ? gzgets($h) : fgets($h)) !== false) yield $line;
  $isGz ? gzclose($h) : fclose($h);
}

$stats = [];
foreach (APPS as $name => $re) {
  $stats[$name] = ['ok' => 0, 'denied' => 0, 'ips' => [], 'daily' => []];
}
$lineRe = '#^(\S+) \S+ \S+ \[(\d{2}/\w{3}/\d{4}):[^\]]*\] "(\S+) (\S+)[^"]*" (\d{3})#';
$totalLines = 0;

foreach ($files as $file) {
  foreach (log_lines($file) as $line) {
    $totalLines++;
    if (!preg_match($lineRe, $line, $m)) continue;
    [, $ip, $date, $method, $rawPath, $status] = $m;
    if ($method !== 'GET') continue;
    if (preg_match('/bot|crawler|spider/i', $line)) continue; // UA中のbotを除外(近似)
    $path = strtok($rawPath, '?');
    foreach (APPS as $name => $re) {
      if (!preg_match($re, $path)) continue;
      if ($status === '200') {
        $stats[$name]['ok']++;
        $stats[$name]['ips'][$ip] = true;
        $d = DateTime::createFromFormat('d/M/Y', $date);
        $key2 = $d ? $d->format('Y-m-d') : $date;
        $stats[$name]['daily'][$key2] = ($stats[$name]['daily'][$key2] ?? 0) + 1;
      } elseif ($status === '403') {
        $stats[$name]['denied']++;
      }
      break;
    }
  }
}

/* ---------- 表示 ---------- */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$days = [];
for ($i = DAYS_TO_SHOW - 1; $i >= 0; $i--) {
  $days[] = date('Y-m-d', time() - $i * 86400);
}
$maxDaily = 1;
foreach ($stats as $s) foreach ($s['daily'] as $v) $maxDaily = max($maxDaily, $v);

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>アプリ利用状況</title>
<style>
  body { font-family: "Meiryo", sans-serif; background: #161331; color: #fff; margin: 0; padding: 1.2em; }
  h1 { font-size: 1.3em; } h2 { font-size: 1.05em; color: #C9D6F5; margin-top: 1.6em; }
  .note { color: #9AA3C7; font-size: .8em; }
  table { border-collapse: collapse; width: 100%; max-width: 720px; margin-top: .6em; }
  th, td { padding: .45em .6em; text-align: right; border-bottom: 1px solid #2B2657; font-size: .9em; }
  th:first-child, td:first-child { text-align: left; }
  thead th { color: #F5C86E; font-weight: bold; }
  .bar { display: inline-block; height: .7em; background: #F5C86E; border-radius: 3px; vertical-align: middle; margin-right: .4em; }
  .zero { color: #575D7E; }
  .card { background: #221E4A; border-radius: 10px; padding: 1em 1.2em; max-width: 720px; }
</style>
</head>
<body>
<h1>🌙 アプリ利用状況</h1>
<p class="note">
  集計対象: 直近<?= DAYS_TO_SCAN ?>日のアクセスログ <?= count($files) ?>ファイル(<?= number_format($totalLines) ?>行) /
  ログ保存をONにした日以降のデータのみ表示されます / bot除外済み
</p>
<?php if (count($files) === 0): ?>
<div class="card" style="border: 1px solid #F5C86E;">
  <b style="color:#F5C86E">まだログファイルがありません</b>
  <p class="note" style="margin-bottom:0">
    サーバーパネルで「ログ保存設定」をONにした<strong>翌日</strong>から、ここに数字が表示されます。
    明日以降にもう一度開いてみてください。
  </p>
</div>
<?php endif; ?>

<div class="card">
<h2 style="margin-top:0">サマリー(ログ全期間)</h2>
<table>
  <thead><tr><th>アプリ</th><th>表示回数</th><th>訪問者数(IP)</th><th>未解錠アクセス</th></tr></thead>
  <tbody>
  <?php foreach ($stats as $name => $s): ?>
    <tr>
      <td><?= esc($name) ?></td>
      <td><?= $s['ok'] ?></td>
      <td><?= count($s['ips']) ?></td>
      <td class="<?= $s['denied'] ? '' : 'zero' ?>"><?= $s['denied'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="note">表示回数=トップページが開かれた回数(200)。未解錠=会員ゲートで止まった回数(403)。訪問者数は同一人物でも回線が変わると別カウントされる近似値です。</p>
</div>

<h2>日別の表示回数(直近<?= DAYS_TO_SHOW ?>日)</h2>
<div class="card">
<table>
  <thead><tr><th>日付</th><?php foreach (APPS as $name => $re): ?><th><?= esc(mb_substr($name, 0, 4)) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($days as $d): ?>
    <tr>
      <td><?= esc(substr($d, 5)) ?></td>
      <?php foreach ($stats as $s): $v = $s['daily'][$d] ?? 0; ?>
        <td class="<?= $v ? '' : 'zero' ?>">
          <?php if ($v): ?><span class="bar" style="width:<?= max(4, (int)(60 * $v / $maxDaily)) ?>px"></span><?php endif; ?><?= $v ?>
        </td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="note">より詳しい分析(参照元・時間帯など)はサーバーパネルの「アクセス解析」を参照。</p>
</body>
</html>
