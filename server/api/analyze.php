<?php
declare(strict_types=1);

// 夢分析エンドポイント: POST {text} -> {title, summary, analysis, imagePrompt}
require __DIR__ . '/_lib.php';

$text = read_text_input();
$raw = call_gemini(analyze_prompt($text), analyze_schema());
$parsed = json_decode($raw, true);
if (!is_array($parsed)) json_out(['error' => '分析結果の形式が不正でした'], 502);
json_out($parsed);
