<?php
declare(strict_types=1);

// 夢日記API 共通ライブラリ(worker/gemini-proxy.js のPHP移植)
// ゲーム解錠システム(games/api/_lib.php)と同じ流儀で書く。PHP 8 必須。
//
// 配置: /app/dream-diary/api/_lib.php
// 設定: /app/_private/config.php (Web非公開。server/_private/config.sample.php 参照)

function cfg(): array {
  static $c = null;
  if ($c === null) $c = require __DIR__ . '/../../_private/config.php';
  return $c;
}

function json_out($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- レート制限(1日あたり) ----------
   ファイル+flockで原子的に加算するため、KV版と違い連打のすり抜けなし。
   障害時は制限なしで通す(会員のサービス停止を避ける。Worker版と同じfail-open方針) */

function daily_rate_ok(): ?string {
  $c = cfg();
  $dir = __DIR__ . '/../../_private/ratelimit';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (mt_rand(1, 50) === 1) cleanup_old_counters($dir); // たまに前日以前の分を掃除
  $day = gmdate('Ymd');
  if (!bump_counter("$dir/dd_all_$day.cnt", (int)($c['daily_limit_global'] ?? 400))) {
    return '本日のコミュニティ全体の利用上限に達しました。明日また試してください';
  }
  $ipKey = hash('sha256', client_ip());
  if (!bump_counter("$dir/dd_ip_{$ipKey}_$day.cnt", (int)($c['daily_limit_ip'] ?? 40))) {
    return '本日の利用上限に達しました。明日また試してください';
  }
  return null;
}

function bump_counter(string $file, int $limit): bool {
  $fp = @fopen($file, 'c+');
  if (!$fp) return true;
  flock($fp, LOCK_EX);
  $n = (int) stream_get_contents($fp);
  $ok = $n < $limit;
  if ($ok) {
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)($n + 1));
  }
  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok;
}

function cleanup_old_counters(string $dir): void {
  $today = gmdate('Ymd');
  foreach (glob($dir . '/dd_*.cnt') ?: [] as $f) {
    if (strpos($f, $today) === false) @unlink($f);
  }
}

/* ---------- 会員ゲート(署名HttpOnly Cookie。ゲーム解錠システムと同方式) ----------
   アプリごとに専用の解錠リンクとCookieを持つ(ゲームの unlock_<game> と同じ考え方)。
   /app/unlock.php?a=<アプリ名>&t=<TOKEN> を開くと発行される。
   Cookieの有効範囲も /app/<アプリ名>/ に限定する */

function member_sign(string $msg): string {
  return hash_hmac('sha256', $msg, (string) cfg()['secret']);
}

// Cookie値: base64url( "<app>|exp|hmac(<app>|exp)" )
function make_app_cookie(string $app, int $exp): string {
  $payload = $app . '|' . $exp;
  $raw = $payload . '|' . member_sign($payload);
  return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function verify_app_cookie(string $app): bool {
  $raw = $_COOKIE['unlock_app_' . $app] ?? '';
  if ($raw === '') return false;
  $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
  if ($decoded === false) return false;
  $parts = explode('|', $decoded);
  if (count($parts) !== 3) return false;
  [$a, $exp, $sig] = $parts;
  if (!hash_equals($app, $a)) return false;
  if ((int) $exp < time()) return false;
  return hash_equals(member_sign($a . '|' . $exp), $sig);
}

function require_app(string $app): void {
  if (!verify_app_cookie($app)) {
    json_out([
      'error' => 'このアプリはコミュニティ会員専用になりました。コミュニティで案内している解錠リンクを一度開いてから、もう一度お試しください。',
    ], 403);
  }
}

// 短時間の試行回数制限(解錠リンクの総当たり対策。ゲームのrate_okと同方式)
function attempt_rate_ok(string $bucket, int $limit, int $windowSec = 60): bool {
  $dir = __DIR__ . '/../../_private/ratelimit';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . '/win_' . $bucket . '_' . hash('sha256', client_ip()) . '.json';
  $now = time();
  $data = ['t' => $now, 'n' => 0];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $d = $raw ? json_decode($raw, true) : null;
    if (is_array($d) && ($now - (int)($d['t'] ?? 0)) < $windowSec) $data = $d;
  }
  $data['n'] = (int)($data['n'] ?? 0) + 1;
  @file_put_contents($file, json_encode($data), LOCK_EX);
  return $data['n'] <= $limit;
}

/* ---------- リクエスト処理 ---------- */

// POST本文から夢のテキストを取り出す(最大4000文字)。レート制限もここで適用
function read_text_input(): string {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'not found'], 404);
  }
  $limited = daily_rate_ok();
  if ($limited !== null) json_out(['error' => $limited], 429);

  $body = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($body)) json_out(['error' => 'bad request'], 400);
  $text = trim(mb_substr((string)($body['text'] ?? ''), 0, 4000));
  if ($text === '') json_out(['error' => 'text required'], 400);
  return $text;
}

/* ---------- プロンプト(アプリ本体・Worker版と同内容をサーバー側に固定) ---------- */

function analyze_prompt(string $text): string {
  return <<<EOT
あなたは優しい夢分析の専門家です。以下はユーザーが見た夢の記録です。

---
{$text}
---

この夢についてJSONで出力してください:
- title: 夢の内容を表す印象的な短いタイトル(15文字以内、日本語)
- summary: 夢の概要(2〜3文、日本語)
- analysis: 夢分析。夢に登場するシンボルや感情から、心理状態や深層心理をやさしく読み解く(200〜300文字、日本語。断定しすぎず、前向きな締めくくりに)
- imagePrompt: この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプト。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。
- todayStep: この夢の分析をふまえ、今日中にできる小さな行動を1つだけ提案する。次の順序で考えること。

  1. category: まず次の7つから1つ選ぶ。夢に出てきた場面・動作・物に最も近いものを選ぶこと。
     - contact : 誰かへの連絡を下書きだけする
     - tidy    : 部屋や持ち物を1箇所だけ片付ける
     - move    : 体を動かす(歩く、伸ばす、外に出る)
     - look    : 気になっていることを短時間だけ調べる、または見返す
     - rest    : 意図的に何もしない時間をとる
     - close   : 保留にしていることに区切りをつける(返事をする、断る、閉じる)
     - write   : 頭の中にあることを紙に書き出す
     ※ write は最後の選択肢とする。他の6つのどれにも当てはまらない場合にだけ選ぶこと。

  2. action: 選んだ category に沿った具体的な行動。5〜15分で終わり、一人で完結すること。
     - 25文字以上30文字以内。30文字を超えてはならない。
     - 動詞で終える。文末に句点(。)をつけない。
     - 「気になっていること」「必要なもの」のような曖昧な対象は使わず、夢に出てきた具体的な対象を必ず名指しすること。
     - 「深呼吸する」「温かい飲み物を飲む」のような、どの夢にも当てはまる決まり文句は使わない。
     - 相手のいる行動は「下書きする」「候補を書き出す」など自分の側で完結する手前で止める。

  3. because: なぜその行動なのかを述べる。
     - 35文字以上40文字以内。40文字を超えてはならない。
     - 夢に実際に出てきた語を引用する。引用は10文字程度までに短く切り、そのあとに理由を続けること。
     - 一般論(「不安の表れ」など)ではなく、この夢にしかない具体物を使う。

  4. minutes: 1〜15の整数。action に書いた行動を実際に終えるのにかかる時間。

  禁止: 受診・服薬・健康の判断、金銭の支出や投資、退職・離婚・絶縁などの重大な決断、他者への詰問。夢の感情が強くつらいものだった場合は rest を選ぶこと。
EOT;
}

function analyze_schema(): array {
  return [
    'type' => 'OBJECT',
    'properties' => [
      'title'       => ['type' => 'STRING'],
      'summary'     => ['type' => 'STRING'],
      'analysis'    => ['type' => 'STRING'],
      'imagePrompt' => ['type' => 'STRING'],
      // categoryを先頭に置くこと。actionを先に書かせると、モデルが行動を決めてから
      // 後付けでcategoryを選ぶため型から選ばせる設計が効かず、提案がwriteに偏る(実測)
      'todayStep'   => [
        'type' => 'OBJECT',
        'properties' => [
          'category' => ['type' => 'STRING', 'enum' => ['contact', 'tidy', 'move', 'look', 'rest', 'close', 'write']],
          'action'   => ['type' => 'STRING'],
          'because'  => ['type' => 'STRING'],
          'minutes'  => ['type' => 'INTEGER'],
        ],
        'required' => ['category', 'action', 'because', 'minutes'],
      ],
    ],
    'required' => ['title', 'summary', 'analysis', 'imagePrompt', 'todayStep'],
  ];
}

function image_prompt_prompt(string $text): string {
  return <<<EOT
以下はユーザーが見た夢の記録です。この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプトを1つ作ってください。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。出力は英語プロンプトのみ(前置きや説明は不要)。

---
{$text}
EOT;
}

/* ---------- Gemini呼び出し(429時は軽量モデルへフォールバック) ---------- */

const TEXT_MODEL = 'gemini-2.5-flash';
const TEXT_MODEL_FALLBACK = 'gemini-3.5-flash-lite';

// 成功時は生成テキストを返す。失敗時はjson_outで応答して終了
function call_gemini(string $prompt, ?array $schema): string {
  $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
  if ($schema !== null) {
    $body['generationConfig'] = [
      'responseMimeType' => 'application/json',
      'responseSchema'   => $schema,
    ];
  }
  $res = gemini_fetch(TEXT_MODEL, $body);
  if ($res['status'] === 429) {
    $res = gemini_fetch(TEXT_MODEL_FALLBACK, $body);
  }
  if ($res['status'] < 200 || $res['status'] >= 300) {
    $msg = 'Gemini HTTP ' . $res['status'];
    $d = json_decode($res['body'], true);
    if (isset($d['error']['message']) && is_string($d['error']['message'])) {
      $msg .= ': ' . $d['error']['message'];
    }
    json_out(['error' => $msg], $res['status'] === 429 ? 429 : 502);
  }
  $data = json_decode($res['body'], true);
  $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
  if (!is_string($text)) json_out(['error' => 'Geminiの応答形式が想定外でした'], 502);
  return $text;
}

function gemini_fetch(string $model, array $body): array {
  // キーはURLに含めず専用ヘッダーで渡す(アクセスログにキーが残らない。Worker版からの改善点)
  $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'x-goog-api-key: ' . (string) cfg()['gemini_api_key'],
    ],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
  ]);
  $out = curl_exec($ch);
  if ($out === false) {
    $err = curl_error($ch);
    curl_close($ch);
    json_out(['error' => '上流への接続に失敗しました: ' . $err], 502);
  }
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return ['status' => $status, 'body' => (string) $out];
}
