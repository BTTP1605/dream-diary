<?php
declare(strict_types=1);

// 利用状況ダッシュボード(デプロイ先: /app/stats.php)【オーナー専用】
//
// エックスサーバーがドメインごとに保存するアクセスログ(<ドメイン>/log/)をPHPから直接読み、
// 各アプリ/ゲームのトップページ表示回数をブラウザで確認できるようにする。
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
  'てのひら診断'   => '#^/app/tesou/(index\.html)?$#',
  'ヤコブの梯子'   => '#^/games/hashigo/(index\.html)?$#',
  '消えた踏切'     => '#^/games/fumikiri/(index\.html)?$#',
  'はざまの図書館' => '#^/games/hazama/(index\.html)?$#',
  // ヒヤッとシミュラクラは Cloudflare Pages(sim.bttp.info) にあるため、
  // このサーバーのアクセスログには現れない。アプリ側から1x1のGIFを
  // 1回だけ取りに来させて、他アプリと同じログ集計に乗せている
  'ヒヤッとシミュラクラ' => '#^/app/beacon-simulacra\.gif$#',
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
  // ログの生データを確認する(形式が想定と違うと集計が0になるため)
  echo "\n== log samples ==\n";
  foreach (glob($logDir . '/*') ?: [] as $f) {
    echo "\n--- $f (" . (is_file($f) ? filesize($f) . 'B, mtime ' . date('Y-m-d H:i', filemtime($f)) : 'dir') . ")\n";
    if (!is_file($f)) continue;
    $n = 0;
    $h = substr($f, -3) === '.gz' ? @gzopen($f, 'rb') : @fopen($f, 'rb');
    if (!$h) { echo "(開けません)\n"; continue; }
    while ($n < 3 && ($l = (substr($f, -3) === '.gz' ? gzgets($h) : fgets($h))) !== false) {
      echo '  ' . rtrim($l) . "\n"; $n++;
    }
    substr($f, -3) === '.gz' ? gzclose($h) : fclose($h);
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
// Xserverのログは行頭にバーチャルホスト名が付く形式:
//   www.bttp.info 1.2.3.4 - - [日時] "GET /path HTTP/2.0" 200 サイズ "参照元" "UA"
// ホスト名なしの素のcombined形式でも読めるよう、先頭フィールドは省略可としている。
$lineRe = '#^(?:\S+ )?(\S+) \S+ \S+ \[(\d{2}/\w{3}/\d{4}):[^\]]*\] "(\S+) (\S+)[^"]*" (\d{3})(?: \S+ "[^"]*" "([^"]*)")?#';
$totalLines = 0;
$matched = 0;

foreach ($files as $file) {
  foreach (log_lines($file) as $line) {
    $totalLines++;
    if (!preg_match($lineRe, $line, $m)) continue;
    $matched++;
    [, $ip, $date, $method, $rawPath, $status] = $m;
    if ($method !== 'GET') continue;
    if (preg_match('/bot|crawler|spider/i', $m[6] ?? '')) continue; // UA中のbotを除外(近似)
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

/* ---------- 手相アプリ: 手の形の判定結果 ---------- */
// /app/tesou/stat.php が追記するCSV。しきい値の校正と「水偏り」の検証用
$tesouCsv = __DIR__ . '/_private/tesou-stats.csv';
// 画面到達のカウンタ(/app/tesou/ev.php が書き出す)。どこで離脱しているかを見る
$funnel = [];
$fp = __DIR__ . '/_private/tesou-funnel.json';
if (is_file($fp)) {
  $j = json_decode((string)@file_get_contents($fp), true);
  if (is_array($j)) {
    foreach ($j as $day => $ev) {
      foreach ($ev as $k => $v) $funnel[$k] = ($funnel[$k] ?? 0) + (int)$v;
    }
  }
}

// 現在の判定バージョン。**古い版の記録を混ぜると分布が読めなくなる**ので、
// 集計は既定でこの版だけを対象にする（?allver=1 で全件表示）
const TESOU_VERSION = 4;

// 現行のしきい値。**CSVを読むループの中でも使うので、必ずループより前で宣言する。**
// PHPのトップレベルの const は上から順に評価されるだけで巻き上げられない。
// 表示部の近くに置いていたせいで Undefined constant で落ちた(2026-08-21)
const TESOU_ASPECT_THR = 1.51;
const TESOU_FINGER_THR = 0.96;          // v3までの指標(中指÷掌の縦)
const TESOU_FINGER_WIDTH_THR = 1.46;    // v4の指標(中指÷掌の横)
$showAllVer = isset($_GET['allver']);

$tesou = ['n' => 0, 'el' => [], 'hand' => [], 'coord' => [], 'snapped' => 0,
          'aspect' => [], 'finger' => [], 'lines' => [], 'ver' => [],
          'spreadA' => [], 'spreadF' => [], 'skipped' => 0, 'fail' => [],
          'poly' => [], 'ids' => [], 'fw' => [], 'v4' => []];
if (is_file($tesouCsv) && ($fh = @fopen($tesouCsv, 'r'))) {
  fgetcsv($fh); // ヘッダ
  while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < 10) continue;
    [$d, $el, $hand, $asp, $fin, $coord, $samples, $lines, $snapped, $v] = $r;
    if (!in_array($el, ['earth', 'air', 'water', 'fire'], true)) continue;
    $tesou['ver'][(int)$v] = ($tesou['ver'][(int)$v] ?? 0) + 1;  // 版の内訳は全件で数える
    // v4 の再分類。aspect と旧指比の定義は v3→v4 で変えていないので、過去の行も計算できる。
    //   指の長短 = 中指÷掌の横 = 旧指比 × aspect
    if ((int)$v >= 3 && (float)$asp > 0 && (float)$fin > 0) {
      $fw = (float)$fin * (float)$asp;
      $tesou['fw'][] = $fw;
      $longPalm = (float)$asp > TESOU_ASPECT_THR;
      $longFing = $fw > TESOU_FINGER_WIDTH_THR;
      $el4 = !$longPalm ? ($longFing ? 'air' : 'earth') : ($longFing ? 'water' : 'fire');
      $tesou['v4'][$el4] = ($tesou['v4'][$el4] ?? 0) + 1;
    }
    if (!$showAllVer && (int)$v !== TESOU_VERSION) { $tesou['skipped']++; continue; }
    $tesou['n']++;
    $tesou['el'][$el] = ($tesou['el'][$el] ?? 0) + 1;
    $tesou['hand'][$hand] = ($tesou['hand'][$hand] ?? 0) + 1;
    $tesou['coord'][$coord] = ($tesou['coord'][$coord] ?? 0) + 1;
    $tesou['lines'][(int)$lines] = ($tesou['lines'][(int)$lines] ?? 0) + 1;
    if ($snapped === '1') $tesou['snapped']++;
    $tesou['aspect'][] = (float)$asp;
    $tesou['finger'][] = (float)$fin;
    // v3 以降で追加した「フレーム間のばらつき」(STICKYの校正に使う)
    if (isset($r[14]) && $r[14] !== '') $tesou['spreadA'][] = (float)$r[14];
    if (isset($r[15]) && $r[15] !== '') $tesou['spreadF'][] = (float)$r[15];
    // 線が取れなかった理由(あとから追加した列。古い行には存在しない)
    if ((int)$lines === 0) {
      $why = (isset($r[16]) && $r[16] !== '') ? $r[16] : '(記録前)';
      $tesou['fail'][$why] = ($tesou['fail'][$why] ?? 0) + 1;
      // no-match の内訳: ふるいを通過した候補線が何本あったか。
      // 0本なら抽出のふるいが厳しすぎ、出ているなら事前分布とのマッチが合っていない
      if (isset($r[17]) && $r[17] !== '') {
        $pc = (int)$r[17];
        $bucket = $pc === 0 ? '候補0本' : ($pc <= 2 ? '候補1〜2本' : ($pc <= 5 ? '候補3〜5本' : '候補6本以上'));
        $tesou['poly'][$bucket] = ($tesou['poly'][$bucket] ?? 0) + 1;
      }
    }
    // どの線が自動で取れたか(1本だけ取れる例が多いので、偏りを見る)
    if ((int)$lines > 0 && isset($r[18]) && $r[18] !== '') {
      $tesou['ids'][$r[18]] = ($tesou['ids'][$r[18]] ?? 0) + 1;
    }
  }
  fclose($fh);
}
function pct_of(int $n, int $total): string { return $total ? number_format(100 * $n / $total, 1) . '%' : '—'; }
function quantile(array $a, float $q): ?float {
  if (!$a) return null;
  sort($a);
  $i = (int) floor(($count = count($a)) * $q);
  return $a[max(0, min($count - 1, $i))];
}
/** しきい値をまたぐ分布を10段階のヒストグラムにする */
function histogram(array $vals, float $lo, float $hi, int $bins = 12): array {
  $h = array_fill(0, $bins, 0);
  foreach ($vals as $v) {
    $i = (int) floor(($v - $lo) / (($hi - $lo) / $bins));
    $h[max(0, min($bins - 1, $i))]++;
  }
  return $h;
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
<link rel="icon" type="image/png" href="data:image/png;base64,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">
<style>
  body { font-family: "Meiryo", sans-serif; background: #161331; color: #fff; margin: 0; padding: 1.2em; }
  h1 { font-size: 1.3em; } h2 { font-size: 1.05em; color: #C9D6F5; margin-top: 1.6em; }
  .note { color: #9AA3C7; font-size: .8em; }
  table { border-collapse: collapse; width: 100%; max-width: 720px; margin-top: .6em; }
  th, td { padding: .45em .6em; text-align: right; border-bottom: 1px solid #2B2657; font-size: .9em; white-space: nowrap; }
  th:first-child, td:first-child { text-align: left; }
  thead th { color: #F5C86E; font-weight: bold; }
  .bar { display: inline-block; height: .7em; background: #F5C86E; border-radius: 3px; vertical-align: middle; margin-right: .4em; }
  .zero { color: #575D7E; }
  /* アプリが増えると日別表の列が伸びるので、スマホでは横スクロールさせる */
  .card { background: #221E4A; border-radius: 10px; padding: 1em 1.2em; max-width: 720px; overflow-x: auto; }
  .card table { width: auto; min-width: 100%; }
</style>
</head>
<body>
<h1>📈 アプリ利用状況</h1>
<p class="note">
  集計対象: 直近<?= DAYS_TO_SCAN ?>日のアクセスログ <?= count($files) ?>ファイル(<?= number_format($totalLines) ?>行中 <?= number_format($matched) ?>行を解析) /
  ログ保存をONにした日以降のデータのみ表示されます / bot除外済み<br>
  ※ サーバーのログは深夜に前日分がまとめて書き出されるため、<strong>当日分と、翌朝までの前日分は「0」のまま</strong>です<?php
    $latest = '';
    foreach ($files as $f) { if (preg_match('/(\d{8})/', basename($f), $mm)) $latest = max($latest, $mm[1]); }
    if ($latest !== '') echo '(最新のログは ' . esc(substr($latest, 0, 4) . '-' . substr($latest, 4, 2) . '-' . substr($latest, 6, 2)) . ' 分まで)';
  ?>
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

<?php if ($funnel):
  // [表示名, 通過率の分母にする段階]。分母が null の段は本流から外れた任意の導線
  $steps = [
    'landing'       => ['トップを開いた', null],
    'select'        => ['手を選んだ', 'landing'],
    'capture_open'  => ['撮影画面へ', 'select'],
    'capture_done'  => ['撮影できた', 'capture_open'],
    'result'        => ['鑑定結果まで到達', 'capture_done'],
    'compare'       => ['両手比較を見た（任意）', null],
    'lineedit'      => ['線の修正に入った（任意）', null],
    'lineedit_done' => ['線を直して鑑定し直した', 'lineedit'],
  ];
  $base = max(1, $funnel['landing'] ?? 1);
?>
<h2>てのひら診断: どこまで進んだか</h2>
<div class="card">
  <table>
    <thead><tr><th>段階</th><th>件数</th><th>トップ比</th><th>前段からの通過率</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($steps as $k => [$ja, $from]): $v = $funnel[$k] ?? 0;
      $den = $from === null ? null : (int)($funnel[$from] ?? 0); ?>
      <tr>
        <td><?= esc($ja) ?></td>
        <td><?= number_format($v) ?></td>
        <td><?= pct_of($v, $base) ?></td>
        <td><?= $den ? pct_of($v, $den) : '—' ?></td>
        <td><span class="bar" style="width:<?= (int)(160 * $v / $base) ?>px"></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="note">
    識別子は持たず、画面ごとの通し番号を数えているだけです（同じ人が2回撮れば2件）。
    <strong>通過率がいちばん低い段階が、改善すると効く場所</strong>です。
    「両手比較」「線の修正」は本流から外れた任意の導線なので通過率は出しません
    （「線を直して鑑定し直した」だけは、修正に入った人を分母にした完走率）。
    <strong>自動検出だけで3本そろうのは約1%</strong>なので、鑑定の質は「線の修正」の利用率で決まります。
  </p>
</div>
<?php endif; ?>

<?php if ($tesou['n'] > 0):
  $EL_JA = ['earth' => '地', 'air' => '風', 'water' => '水', 'fire' => '火'];
  $elMax = max($tesou['el']);
  // 実測は aspect 1.3〜1.7 / 指比 0.7〜1.2 に収まるので、その範囲を細かく見る
  $aspH = histogram($tesou['aspect'], 1.2, 1.8);
  $finH = histogram($tesou['finger'], 0.7, 1.3);
  $hMax = max(max($aspH), max($finH), 1);
?>
<h2>てのひら診断: 手の形の判定</h2>
<div class="card">
  <p class="note" style="margin-top:0">
    鑑定 <?= number_format($tesou['n']) ?> 件（写真は受け取っていません。分類結果と2つの比率のみ）
    <?php if (!$showAllVer && $tesou['skipped']): ?>
      <br><strong style="color:#F5C86E">現在の判定 v<?= TESOU_VERSION ?> のみを集計中</strong>
      — 旧版 <?= $tesou['skipped'] ?> 件は除外しています（しきい値が違うため混ぜると分布が読めません）。
      <a href="?allver=1" style="color:#9AA3C7">全件を見る</a>
    <?php elseif ($showAllVer): ?>
      <br><strong style="color:#F5C86E">全バージョンを合算中</strong>
      — しきい値の異なる記録が混ざっています。
      <a href="?" style="color:#9AA3C7">現行版のみに戻す</a>
    <?php endif; ?>
  </p>
  <table>
    <thead><tr><th>元素</th><th>件数</th><th>割合</th><th>分布</th></tr></thead>
    <tbody>
    <?php foreach (['earth','air','water','fire'] as $k): $v = $tesou['el'][$k] ?? 0; ?>
      <tr>
        <td><?= $EL_JA[$k] ?>（<?= esc($k) ?>）</td>
        <td><?= $v ?></td>
        <td><?= pct_of($v, $tesou['n']) ?></td>
        <td><span class="bar" style="width:<?= (int)(160 * $v / max(1,$elMax)) ?>px"></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="note">
    4元素が均等になる必要はありません。ただし<strong>1つが極端に多い</strong>場合は、
    しきい値か座標系の偏りを疑ってください（<code>tesou-app/CALIBRATION.md</code>）。
  </p>
</div>

<?php if ($tesou['v4']):
  $v4n = array_sum($tesou['v4']);
  $EL_JA = ['earth' => '地', 'air' => '風', 'water' => '水', 'fire' => '火'];
?>
<h2>てのひら診断: v4（中指÷掌の横）で数え直すと</h2>
<div class="card">
  <p class="note">
    <strong>過去の記録<?= (int)$v4n ?>件を、v4の判定でそのまま数え直した結果です。</strong>
    aspect と旧指比の定義は v3→v4 で変えていないので、遡って計算できます。
    v4は「指の長短を掌の<em>縦</em>ではなく<em>横</em>と比べる」もので、
    掌の形の判定と辺を分けることで2軸の連動を外しています。
  </p>
  <table>
    <thead><tr><th>元素</th><th>件数</th><th>割合</th><th>分布</th></tr></thead>
    <tbody>
    <?php $v4max = max($tesou['v4']); foreach (['earth', 'air', 'water', 'fire'] as $k):
      $c = (int)($tesou['v4'][$k] ?? 0); ?>
      <tr>
        <td><?= esc($EL_JA[$k]) ?></td>
        <td><?= number_format($c) ?></td>
        <td><?= pct_of($c, (int)$v4n) ?></td>
        <td><span class="bar" style="width:<?= (int)(160 * $c / max(1, $v4max)) ?>px"></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
    $fwv = $tesou['fw']; sort($fwv); $fwn = count($fwv);
    $fq = function (float $p) use ($fwv, $fwn) { return $fwv[max(0, min($fwn - 1, (int)floor($fwn * $p)))]; };
  ?>
  <p class="note">
    指の指標(中指÷掌の横)の分布 — 下位25% <?= number_format($fq(0.25), 3) ?>
    / 中央値 <?= number_format($fq(0.50), 3) ?>
    / 上位25% <?= number_format($fq(0.75), 3) ?>
    　現在の境界 <?= TESOU_FINGER_WIDTH_THR ?>（<?= pct_of(count(array_filter($fwv, fn($x) => $x > TESOU_FINGER_WIDTH_THR)), $fwn) ?> が長い指）
    <br>
    <strong>境界を中央値に置けば長い指はちょうど50%になります。</strong>
    それより下げると「水(長方形×長指)」が厚くなり、上げると「火(長方形×短指)」が厚くなります。
    水は利用者の反応が良い型なので、やや水寄りに置いてあります。
  </p>
</div>
<?php endif; ?>

<h2>校正用: 判定に使った数値の分布</h2>
<div class="card">
  <table>
    <thead><tr><th>指標</th><th>下位25%</th><th>中央値</th><th>上位25%</th><th>境界</th><th>境界超えの割合</th></tr></thead>
    <tbody>
      <?php
        $aspOver = count(array_filter($tesou['aspect'], fn($v) => $v > TESOU_ASPECT_THR));
        $finOver = count(array_filter($tesou['finger'], fn($v) => $v > TESOU_FINGER_THR));
      ?>
      <tr>
        <td>掌の縦横比 aspect</td>
        <td><?= number_format(quantile($tesou['aspect'], 0.25), 2) ?></td>
        <td><?= number_format(quantile($tesou['aspect'], 0.50), 2) ?></td>
        <td><?= number_format(quantile($tesou['aspect'], 0.75), 2) ?></td>
        <td><?= TESOU_ASPECT_THR ?></td>
        <td><?= pct_of($aspOver, $tesou['n']) ?> が長方形</td>
      </tr>
      <tr>
        <td>指の長さ比 fingerRatio</td>
        <td><?= number_format(quantile($tesou['finger'], 0.25), 2) ?></td>
        <td><?= number_format(quantile($tesou['finger'], 0.50), 2) ?></td>
        <td><?= number_format(quantile($tesou['finger'], 0.75), 2) ?></td>
        <td><?= TESOU_FINGER_THR ?></td>
        <td><?= pct_of($finOver, $tesou['n']) ?> が長い指</td>
      </tr>
    </tbody>
  </table>
  <p class="note" style="margin-bottom:.3em">
    <strong>aspect の分布</strong>（1.2〜1.8 を12段階。色付きが境界 <?= TESOU_ASPECT_THR ?>）
  </p>
  <table><tbody><tr>
    <?php $st = 0.6/12; foreach ($aspH as $i => $v): $lo = 1.2 + $i * $st; ?>
      <td style="text-align:center;<?= ($lo <= TESOU_ASPECT_THR && $lo + $st > TESOU_ASPECT_THR) ? 'background:#3A2E63' : '' ?>">
        <div style="height:<?= (int)(40 * $v / $hMax) ?>px;background:#F5C86E;border-radius:2px"></div>
        <span style="font-size:.72em;color:#9AA3C7"><?= number_format($lo, 2) ?></span>
      </td>
    <?php endforeach; ?>
  </tr></tbody></table>
  <p class="note" style="margin-bottom:.3em">
    <strong>fingerRatio の分布</strong>（0.7〜1.3 を12段階。色付きが境界 <?= TESOU_FINGER_THR ?>）
  </p>
  <table><tbody><tr>
    <?php $st = 0.6/12; foreach ($finH as $i => $v): $lo = 0.7 + $i * $st; ?>
      <td style="text-align:center;<?= ($lo <= TESOU_FINGER_THR && $lo + $st > TESOU_FINGER_THR) ? 'background:#3A2E63' : '' ?>">
        <div style="height:<?= (int)(40 * $v / $hMax) ?>px;background:#F5C86E;border-radius:2px"></div>
        <span style="font-size:.72em;color:#9AA3C7"><?= number_format($lo, 2) ?></span>
      </td>
    <?php endforeach; ?>
  </tr></tbody></table>
  <p class="note">
    左右: <?php foreach (['left'=>'左手','right'=>'右手'] as $k=>$ja) { echo "$ja " . ($tesou['hand'][$k] ?? 0) . "件　"; } ?>
    / 座標系: <?php foreach ($tesou['coord'] as $k=>$v) { echo esc($k) . " {$v}件　"; } ?>
    / 履歴で判定が変わった: <?= $tesou['snapped'] ?>件（<?= pct_of($tesou['snapped'], $tesou['n']) ?>）
  </p>
  <p class="note">
    判定バージョン: <?php foreach ($tesou['ver'] as $k=>$v) { echo "v{$k} {$v}件　"; } ?>
    <?php if ($tesou['spreadA']): ?>
      / <strong>同一測定内のばらつき(中央値)</strong>:
      aspect <?= number_format(quantile($tesou['spreadA'], 0.5), 3) ?>
      / 指比 <?= number_format(quantile($tesou['spreadF'], 0.5), 3) ?>
      → STICKY はこの2倍前後が目安
    <?php endif; ?>
  </p>
  <p class="note">
    掌線の検出本数: <?php for ($i=0;$i<=3;$i++) { echo "{$i}本 " . ($tesou['lines'][$i] ?? 0) . "件　"; } ?>
  </p>
  <?php if ($tesou['fail']): arsort($tesou['fail']); $failTotal = array_sum($tesou['fail']); ?>
  <p class="note">
    <strong>0本だった理由の内訳</strong>（<?= $failTotal ?>件）—
    <?php foreach ($tesou['fail'] as $why => $n): ?>
      <?= htmlspecialchars((string)$why) ?> <?= (int)$n ?>件（<?= pct_of((int)$n, (int)$failTotal) ?>）
    <?php endforeach; ?>
    <br>
    empty-mask=掌の領域が取れない／no-match=線は出たが同定できない／warp:・detect:=その段で例外。
    <em>(記録前)</em> は理由を残す前に集めた分で、内訳は不明。
  </p>
  <?php endif; ?>
  <?php if ($tesou['poly']): arsort($tesou['poly']); $pTotal = array_sum($tesou['poly']); ?>
  <p class="note">
    <strong>0本のとき、候補線は何本出ていたか</strong>（<?= (int)$pTotal ?>件）—
    <?php foreach ($tesou['poly'] as $b => $n): ?>
      <?= htmlspecialchars((string)$b) ?> <?= (int)$n ?>件（<?= pct_of((int)$n, (int)$pTotal) ?>）
    <?php endforeach; ?>
    <br>
    <strong>候補0本が多いなら抽出のふるいが厳しすぎ、候補が出ているなら事前分布とのマッチが合っていない。</strong>
    打ち手が正反対になるので、ここで見分ける。
  </p>
  <?php endif; ?>
  <?php if ($tesou['ids']): arsort($tesou['ids']); $iTotal = array_sum($tesou['ids']); ?>
  <p class="note">
    <strong>自動で取れた線の組み合わせ</strong>（<?= (int)$iTotal ?>件）—
    <?php foreach ($tesou['ids'] as $c => $n): ?>
      <?= htmlspecialchars((string)$c) ?> <?= (int)$n ?>件（<?= pct_of((int)$n, (int)$iTotal) ?>）
    <?php endforeach; ?>
    <br>
    特定の線だけ取れる／取れない偏りがあれば、その線の事前分布がずれている。
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="note">より詳しい分析(参照元・時間帯など)はサーバーパネルの「アクセス解析」を参照。</p>
</body>
</html>
