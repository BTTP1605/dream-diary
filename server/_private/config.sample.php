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

  // 会員ゲート(Phase 2)。ランダム値はローカルのセットアップスクリプトが自動生成する
  'secret' => 'ここにHMAC用の64文字ランダム16進数',

  // アプリごとの解錠トークン(ゲームのconfigと同構造)。
  // 解錠リンク: /app/unlock.php?a=<キー名>&t=<token>
  // 無効化はここの active を false にするか token を差し替えるだけ(再デプロイ不要)
  'apps' => [
    'dream-diary' => ['token' => 'ここに夢日記用トークン', 'active' => true],
    // 'lucid-dream' => ['token' => '移行時に追加', 'active' => true],
  ],
];
