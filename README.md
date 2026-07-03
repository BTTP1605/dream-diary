# 🌙 夢日記

昨日見た夢をテキストか音声で記録すると、AIがタイトル・概要・夢分析を生成し、夢のワンシーンを16:9の画像で再現するWebアプリ。スマホでの利用を想定。

## 特徴

- **完全無料で動作**(Gemini API無料枠 + FLUX.1-schnell無料Space + AI Horde)
- 音声入力対応(ブラウザ標準のWeb Speech API・日本語)
- 画像の登場人物は日本人・舞台は日本がデフォルト
- 記録データはすべて端末内(IndexedDB)に保存。サーバーには何も送信されません(AI APIへのリクエストを除く)

## 使い方

1. アプリを開き、右上の⚙️設定で [Google AI Studio](https://aistudio.google.com/apikey) の無料APIキーを登録
2. 「＋」から夢を入力して記録
3. 一覧から記録をタップすると、画像・概要・夢分析・元テキストを確認できます

画像生成は Gemini → FLUX.1-schnell(キー不要)→ AI Horde の順に自動フォールバックします。
詳しい経緯は [DEVELOPMENT.md](DEVELOPMENT.md) を参照。

## ローカル起動

```
npx http-server . -p 4173 -c-1
```

ブラウザで http://localhost:4173 を開く。
