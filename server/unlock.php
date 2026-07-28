<?php
declare(strict_types=1);

// 会員解錠エンドポイント(デプロイ先: /app/unlock.php)
//
// コミュニティ内で案内するリンク: https://bttp.info/app/unlock.php?t=<TOKEN>
// 正しいトークンなら署名HttpOnly Cookie(1年・path=/app/)を発行し、夢日記へ転送する。
// ゲームの unlock.php と同方式。note購入導線がないため参照元検証は行わない。
// トークンの無効化・差し替えは /app/_private/config.php の member_token 変更のみ(再デプロイ不要)。

require __DIR__ . '/dream-diary/api/_lib.php';

function html_out(string $title, string $body, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
     . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
     . "<title>{$title}</title></head>"
     . '<body style="font-family:sans-serif;max-width:480px;margin:3em auto;padding:0 1em;">'
     . $body . '</body></html>';
  exit;
}

// 総当たり対策: 1分あたり10回まで
if (!attempt_rate_ok('unlock', 10)) {
  html_out('しばらくお待ちください',
    '<h2>試行回数が多すぎます</h2><p>1分ほど待ってから、もう一度お試しください。</p>', 429);
}

$token = (string) ($_GET['t'] ?? '');
$cfgToken = (string) (cfg()['member_token'] ?? '');
if ($token === '' || $cfgToken === '' || !hash_equals($cfgToken, $token)) {
  html_out('リンクが無効です',
    '<h2>解錠リンクが無効です</h2><p>コミュニティ内で案内している最新のリンクから、もう一度開いてください。</p>', 403);
}

$exp = time() + 365 * 24 * 60 * 60; // 1年
setcookie('app_member', make_member_cookie($exp), [
  'expires'  => $exp,
  'path'     => '/app/',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'Lax',
]);

header('Location: /app/dream-diary/', true, 302);
exit;
