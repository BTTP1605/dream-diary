<?php
declare(strict_types=1);

// 画像プロンプト生成エンドポイント: POST {text} -> {imagePrompt}
require __DIR__ . '/_lib.php';

require_member();
$text = read_text_input();
$raw = call_gemini(image_prompt_prompt($text), null);
json_out(['imagePrompt' => trim($raw)]);
