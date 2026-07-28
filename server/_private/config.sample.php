<?php
// /app/_private/config.php のひな形。
//
// 実際のキー入りファイルはGitに置かない。ローカルでは
//   C:\Users\spect\Claude-code\.xserver-app-config.local.php
// にコピーしてキーを記入し、デプロイスクリプトが /app/_private/config.php へアップロードする。
return [
  // Google AI Studio (https://aistudio.google.com/apikey) で取得した無料キー
  'gemini_api_key' => 'ここにGeminiのAPIキーを貼り付け',

  // レート制限(1日あたり)。Worker版の値を踏襲
  'daily_limit_ip'     => 40,
  'daily_limit_global' => 400,
];
