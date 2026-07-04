# 夢日記アプリ 開発記録

(旧称「ゆめにっき」。2026-07-04にタイトルを漢字の「夢日記」へ変更)

最終更新: 2026-07-04

## 1. コンセプト

- **日本人向けの夢日記Webアプリ**。スマホでの利用が基本。
- ユーザーが昨日見た夢をテキストまたはマイクで入力すると、AIがタイトル・概要・夢分析を生成し、夢のワンシーンを16:9の横長画像で再現する。
- **画像の登場人物は日本人、舞台は日本がデフォルト**(夢の中で特に指定がない場合)。
- 記録は「日付+タイトル」で一覧表示。タップで詳細画面(画像・概要・夢分析・元テキスト)。
- **ランニングコストは完全無料**が絶対条件。

## 2. 構成

| 項目 | 採用技術 | 備考 |
|---|---|---|
| フロントエンド | 素のHTML/CSS/JS(ビルドなし) | `index.html` / `style.css` / `app.js` の3ファイル |
| ホスティング | ローカル(http-server)→将来GitHub Pages予定 | 静的サイトなのでどこでも動く |
| 夢分析(テキスト) | Gemini API `gemini-2.5-flash`(429時 `gemini-2.5-flash-lite` に自動フォールバック) | 無料枠で動作確認済み |
| 画像生成 | ①Gemini画像モデル(自動検出)→②FLUX.1-schnell(HF公式Space・キー不要)→③AI Horde | 経緯は下記 |
| 音声入力 | Web Speech API(`webkitSpeechRecognition`, ja-JP) | https または localhost でのみ動作 |
| データ保存 | IndexedDB(DB名 `dreamDiary` / ストア `entries`) | 画像はBlobで保存。すべて端末内 |
| APIキー保存 | localStorage | `dreamDiary.apiKey`(Gemini) / `dreamDiary.hordeKey`(AI Horde) / `dreamDiary.imageModel`(検出済み画像モデル) |

### 記録データの形式(IndexedDB entries)

```js
{
  id: 1751600000000,        // Date.now() = 記録日時(一覧の日付表示にも使用)
  title: "...",             // AI生成タイトル(15文字以内)
  summary: "...",           // AI生成概要(2〜3文)
  analysis: "...",          // AI生成夢分析(200〜300字)
  originalText: "...",      // ユーザー入力の元テキスト
  imagePrompt: "...",       // 画像生成用の英語プロンプト(AI生成)
  image: Blob | null,       // 生成画像
  pendingJob: { id, submittedAt } | なし  // AI Horde順番待ち中のジョブ
}
```

## 3. 開発経緯(時系列)

### 2026-07-03〜04

1. **初期実装**: Gemini API 1本構成(テキスト=2.5-flash、画像=2.5-flash-image)で作成。
   設定画面でAPIキーを入力する方式。UI検証済み。
2. **問題①: Geminiの画像生成が無料枠で使えない**
   - エラー: `HTTP 429 ... limit: 0` — 使いすぎではなく、**無料枠の割り当て自体がゼロ**。
   - このキーで見えた画像系モデル6種(gemini-2.5-flash-image / gemini-3-pro-image(-preview) / gemini-3.1-flash-image(-preview) / gemini-3.1-flash-lite-image)**すべてが limit: 0**。
   - ネット上の「1日500枚無料」情報は古い。テキスト系の無料枠は生きている。
   - 対応: 画像モデルを自動検出する仕組みを実装(将来無料枠が復活したら自動でGeminiを使う)。
3. **問題②: Pollinations.ai は使えない**
   - 匿名利用にCloudflare Turnstile(bot認証)が必須になっており、アプリからの自動利用は不可。撤去済み。
4. **AI Horde 導入**(現行の画像生成手段)
   - 有志GPUの無料共有ネットワーク。登録不要(匿名キー `0000000000`)でも利用可。
   - 匿名は混雑時に制限(一定計算コスト以上は拒否)→ **設定を段階的に軽くして再試行するラダー**を実装
     (576x320+RealESRGAN_x4plus → 576x320 → 448x256)。
   - 順番待ちが長い(匿名で数十分)→ **「ジョブ登録→記録は即保存→完成したら自動取り込み」方式**に変更。
     30秒ごとにポーリング(`pollPendingJobs`)、順番待ち状況(○番目・約○分)を詳細画面に表示。
   - 無料登録キー(stablehorde.net/register)を設定画面に入力すると優先度が上がる。ユーザー登録済み。
5. **問題③: 夢と無関係な画像が生成される**
   - 原因: 初期バージョンで保存した記録に `imagePrompt` が無く、**日本語の概要文がそのままAI Hordeに送られていた**(SD系モデルは日本語をほぼ理解できない)。
   - 対応: `ensureImagePrompt()` — 英語プロンプトがない記録は、生成前にGeminiテキストで英語プロンプトを自動作成して保存。ネガティブプロンプトも追加。
6. **UI修正**
   - `hidden`属性がCSSの`display: block`に負けるバグ → `[hidden] { display: none !important; }` で修正。
   - 画像がある記録にも「🖼 画像を作り直す」ボタンを常時表示。
   - 順番待ち中の「待たずに作り直す」には確認ダイアログ(列の最後尾に戻るため)。
7. **問題④(未解決): AI Hordeの画像品質・日本らしさ**
   - プロンプトで Japanese / Japan を指定しても、**中国風の町並みなど「日本らしくない」画像になりがち**。
   - SD系モデルはGemini(Nano Banana)よりプロンプト忠実度が低く、待ち時間も長い。
   - → **代替の無料画像生成API(無料登録キー方式)を検討中**(Hugging Face Inference APIなど)。

### 2026-07-04(続き)

8. **代替画像生成サービスの調査**
   - Hugging Face Inference Providers: 無料枠が月$0.10相当と少なすぎ → 不採用。
   - Cloudflare Workers AI: 無料枠は大きいがブラウザ直呼び不可(Worker構築が必要)→ 不採用。
   - Together AI FLUX.1-schnell-Free: 一度導入したが、**登録時にクレジットカードが必須**と判明 → 撤去。
   - Leonardo.ai: 無料150トークン/日はWebアプリ専用。APIは従量課金($5初回クレジットのみ)→ 不採用。
9. **FLUX.1-schnell(Hugging Face公式Space / ZeroGPU)を採用**(現行)
   - `black-forest-labs-flux-1-schnell.hf.space` のGradio APIを直接呼ぶ方式。
   - **登録・APIキー・カードすべて不要**。ブラウザからCORSで呼べることを実測確認。
   - 実測: 匿名のまま約20秒で 1024x576(正確な16:9)のwebpを生成。
   - 制約: 匿名利用のGPU枠(IP単位)を超えると一時エラー(時間経過で回復)。その場合はAI Hordeへ自動フォールバック。
   - 生成優先順位: **Gemini → FLUX Space(キー不要)→ AI Horde** の三段構え(`generateImageFast`)。

## 4. 現在の課題・TODO

- [x] 画像生成の代替サービス検討 → FLUX.1-schnell(HF公式Space・キー不要)を導入(2026-07-04)
- [ ] FLUX Space経由での実生成の品質確認(日本の街並み・日本人の再現度)
- [x] GitHub Pagesへの公開(2026-07-04)
  - 公開URL: https://bttp1605.github.io/dream-diary/
  - リポジトリ: https://github.com/BTTP1605/dream-diary (mainブランチ / root をPagesが配信)
  - **運用ルール**: mainがそのまま本番になるため、今後の変更は作業ブランチ+ローカル検証後にmainへマージ(lucid-dream-appと同じ方針)
- [ ] (任意)PWA化(ホーム画面追加・オフライン閲覧)

## 5. コミュニティ運用(キー不要化)

約20名のコミュニティ会員に、**APIキー設定なし**で使ってもらうための構成(2026-07-04実装)。

### 仕組み

- Cloudflare Worker(無料プラン)を「夢分析専用の中継サーバー」にする(`worker/gemini-proxy.js`)。
- Geminiキーは**WorkerのSecretにのみ保存**。会員の端末やアプリのコードには一切含まれない。
- Workerは `/analyze`(夢分析)と `/image-prompt`(英語プロンプト生成)の2エンドポイントのみ。
  プロンプトはサーバー側に固定されているため、汎用のGemini中継としては悪用できない。
- 許可オリジン(公開サイトとlocalhost)以外からのリクエストは403で拒否。
- アプリ側は `app.js` 冒頭の `PROXY_BASE` にWorkerのURLを設定すると有効になる。
  自分のGeminiキーを設定しているユーザーは従来どおり直接Geminiを呼ぶ(プロキシ不使用)。
- 画像生成は元からキー不要(FLUX Space / AI Horde)のため変更なし。

### 料金について

- Gemini無料枠は**課金情報を登録しない限り請求が発生しない**(上限到達時はエラーになるだけ。翌日リセット)。
- Cloudflare Workers無料プラン(1日10万リクエスト)も同様にカード登録不要。
- 20名×1日1〜2回の利用なら、Geminiテキスト無料枠(flash→flash-lite自動フォールバック)で収まる想定。

### オーナーのセットアップ手順(初回のみ)

1. https://dash.cloudflare.com/sign-up でCloudflare無料アカウントを作成(カード不要)
2. ダッシュボード → **Workers & Pages** → **Create** → **Create Worker** → 名前を `yume-nikki-proxy` などにして **Deploy**
3. **Edit code** を開き、`worker/gemini-proxy.js` の内容をすべて貼り付けて **Deploy**
4. Workerの **Settings → Variables and Secrets** → **Add** → Type: **Secret**、
   Variable name: `GEMINI_API_KEY`、Value: 自分のGeminiキー → **Save**
5. Workerの URL(`https://yume-nikki-proxy.xxxx.workers.dev`)を控えて、
   アプリ側 `app.js` の `PROXY_BASE` に設定 → 検証後にmainへマージして公開

## 6. 運用メモ

- Gemini APIキー: https://aistudio.google.com/apikey (無料・カード不要)
- FLUX.1-schnell Space: https://huggingface.co/spaces/black-forest-labs/FLUX.1-schnell (キー不要・アプリが自動利用)
- AI Horde: https://stablehorde.net/register (無料登録でキー取得、順番待ち短縮)
- ブラウザを閉じてもAI Hordeの生成は継続するが、完成結果のサーバー保持は短時間(約20分)。
  取り込めなかった場合は「作成中」表示が自動解除され、「画像を生成する」で再依頼できる。
- ローカル起動: `npx http-server dream-diary -p 4173 -c-1` → http://localhost:4173
