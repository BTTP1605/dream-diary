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
    ],
    'required' => ['title', 'summary', 'analysis', 'imagePrompt'],
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
const TEXT_MODEL_FALLBACK = 'gemini-2.5-flash-lite';

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
