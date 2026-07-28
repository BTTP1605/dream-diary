# エックスサーバー移行設計書(dream-diary / lucid-dream-app)

対象: `https://bttp1605.github.io/dream-diary/` と `https://BTTP1605.github.io/lucid-dream-app/` を
エックスサーバー `https://bttp.info/app/` 配下へ移行し、dream-diary はサーバー保存(端末間同期)まで拡張する。

- 作成日: 2026-07-29(設計のみ。実装フェーズ着手時に本書を更新すること)
- 前提: ゲーム3作の移行で構築済みの仕組み(`bttp.info/games/`、unlock.php+署名HttpOnly Cookie、
  `_private/` 直アクセス403、xserver-deployツール)を最大限流用する

## 0. 配置と重要な前提

| アプリ | 新URL |
|---|---|
| 夢日記 | `https://bttp.info/app/dream-diary/` |
| 明晰夢誘導 | `https://bttp.info/app/lucid-dream/` |

**注意: `/app/` はサブディレクトリであり、`/games/` と同一オリジン。**
localStorage・IndexedDB・Cookieの空間をゲームと共有する。緩和策:

- 認証・同期の秘密はすべて **HttpOnly Cookie**(JSから読めない)に置く。localStorageに置くのは
  盗まれても実害の小さいもの(設定・同期ID)のみ
- localStorageキーは既に `dreamDiary.` プレフィックスつき。今後も全アプリでプレフィックス必須
- `.htaccess` でCSPを配布し、万一のXSSの影響範囲を狭める(§5)
- 将来オリジン分離が必要になったら `app.bttp.info` サブドメイン化を再検討(DNS+無料SSL追加のみ。
  その場合も本設計のPHP/DB部分はそのまま使える)

## 1. フェーズ構成(それぞれ独立してリリース可能)

- **Phase 0(完了)**: アプリ内バックアップ(画像込みJSON書き出し/読み込み)。移行の橋渡し兼、恒久的な保険
- **Phase 1**: 静的ファイル移行 + Gemini中継のPHP化(Cloudflare Worker廃止)
- **Phase 2**: 会員ゲート(ゲームと同じ署名Cookie方式)
- **Phase 3**: 夢日記のサーバー保存・端末間同期
- lucid-dream-app は **dream-diary の移行完了後に着手**。Phase 1+2相当のみで完了
  (サーバー機能は不要だが、会員ゲートは dream-diary と同方式で実装する。§3)

## 2. Phase 1: 静的移行とPHPプロキシ

### ディレクトリ構成(public_html配下)

```
app/
├── .htaccess                 # セキュリティヘッダー、_private遮断
├── _private/                 # Web非公開(403)。設定・DB・画像の実体
│   ├── .htaccess             # Require all denied
│   ├── config.php            # GEMINI_API_KEY / HMAC_SECRET / 会員トークン
│   ├── app.db                # SQLite(レート制限・Phase3の記録)
│   └── img/{sync_id}/        # Phase 3: 夢の画像
├── dream-diary/
│   ├── index.html / app.js / style.css
│   └── api/
│       ├── analyze.php       # worker/gemini-proxy.js の /analyze を移植
│       ├── image-prompt.php  # 同 /image-prompt
│       └── (Phase3) entries.php / sync.php
└── lucid-dream/              # CRAのbuild出力をそのまま配置
```

### PHPプロキシ(Workerの置き換え)

- **同一オリジンになるためCORS不要**。Originヘッダー検証(偽装可能だった)は廃止し、
  Phase 2以降は会員Cookie必須にする。Phase 1時点ではレート制限のみで公開
- プロンプト・応答スキーマは `worker/gemini-proxy.js` の内容をそのまま移植(用途固定は維持)
- Geminiキーは `_private/config.php` に定義(`<?php return [...];` 形式)。webからは403、
  Gitにはコミットしない(xserver-deployの `.local` 慣例に従いローカルは `config.local.php` で管理)
- レート制限: SQLiteでIP/日・全体/日をカウント。KVと違い原子的に更新できるため
  連打すり抜けなし。上限は現行踏襲(IP 40回/日・全体 400回/日)し、Phase 2で会員単位に変更
- 429時の flash→flash-lite フォールバックも移植

### アプリ側の変更(dream-diary)

- `app.js` の `PROXY_BASE` を相対パス `"api"` に変更(bttp.info配布版)。
  github.io版は当面Workerを使い続けるため、**併存期間中は両者のPROXY_BASEが異なる**
  (デプロイスクリプトで書き換えるか、`location.hostname` で自動切替)
- Workerは全会員の移行完了後に削除。それまで現状のまま維持(変更不要)

### lucid-dream-app

- `package.json` の `homepage` を `https://bttp.info/app/lucid-dream` に変更 → `npm run build` → `build/` をアップロード
- 音声ファイルは `PUBLIC_URL` 経由参照のため自動追随。Service Worker未使用なのでキャッシュ事故なし
- 運用ルール(gh-pages直接変更禁止・作業ブランチ+検証後に反映)は本番リポジトリ側の手順に従う

### デプロイ

- 既存の `xserver-deploy`(basic-ftp)に `--app dream-diary|lucid-dream` を追加
- FTPアカウント `games@bttp.info` は `public_html/games` 固定のため、
  `app@bttp.info`(接続先 `public_html/app`)を新規作成。認証情報は `.xserver-ftp.local.json` に追記

## 3. Phase 2: 会員ゲート

ゲームの解錠システムと同じ方式(検証済みのコードを流用):

- コミュニティ内で `https://bttp.info/app/unlock.php?t=<TOKEN>` を告知
  → PHPがトークン+レート制限を検証 → **署名(HMAC)つきHttpOnly Cookie(1年・path=/app/)** を発行
  → 以後 `api/*.php` はCookie検証を通った場合のみ応答
- ゲームと違いnote購入導線がないため、**referer検証は行わない**(コミュニティ投稿からの遷移のみ)
- トークンはゲーム同様 `config.php` で無効化・差し替え可能(再ビルド不要)
- dream-diary の静的ファイル(HTML/JS)自体は公開のままで良い(秘密を含まない)。ゲートするのはAPIのみ
- 効果: 「Origin偽装でタダ乗り可能」問題が構造的に解消。レート制限も会員Cookie単位に変更

### lucid-dream-app の会員ゲート(決定済み: dream-diaryと同方式でゲートする)

- 会員Cookieは path=/app/ で発行するため、**1回の解錠で /app/ 配下の全アプリ
  (夢日記・明晰夢)が使える**。アプリごとの解錠リンク配布は不要
- lucid-dream にはAPIがないため、ページ本体をゲートする:
  - ビルド出力の `index.html` は `_private/lucid-dream-index.html` に置き(Web直アクセス不可)、
    公開側は `index.php` が会員Cookieを検証してからその中身を出力する。
    未解錠なら解錠案内ページを表示(`DirectoryIndex index.php` を設定)
  - JS/CSS/音声などのアセットは公開のまま(秘密を含まず、単体では意味を持たないため実害なし。
    厳密にゲートしたくなったらRewriteで全アセットをPHP経由配信に変更可能だが初期は不要)
  - デプロイスクリプト(§2)が `npm run build` 後の index.html 移設まで自動化する

## 4. Phase 3: サーバー保存・端末間同期(dream-diary)

### 方針

- **IndexedDBは今後もオフラインキャッシュとして維持**(現行の使い勝手を変えない)。
  サーバーはその同期先。オフラインでも閲覧・記録でき、次回オンライン時に同期
- 個人情報を集めない: アカウント・メール・パスワードは作らない。
  サーバーが発行する**同期コード**(例: `KA7F-3PQM-X2`)だけで端末を紐付ける

### 同期コードのフロー

1. 設定画面「サーバー保存を有効にする」→ `sync.php` が sync_id とシークレットを発行
   - シークレットはHttpOnly Cookieへ、sync_id(表示用の同期コード)は画面表示+localStorage
2. 別端末では同期コードを入力 → 確認のうえ同じ記録に紐付け(シークレットCookie再発行)
3. コードを忘れたら: 旧端末の設定画面で再表示できる。全端末を失った場合は復元不可
   → だからこそ**手元バックアップ(Phase 0)を併設したまま残す**

### データモデル(SQLite `_private/app.db`)

```sql
CREATE TABLE members (
  sync_id     TEXT PRIMARY KEY,
  secret_hash TEXT NOT NULL,          -- password_hash()
  created_at  INTEGER NOT NULL
);
CREATE TABLE entries (
  sync_id     TEXT NOT NULL,
  id          INTEGER NOT NULL,       -- クライアントのDate.now()をそのまま使用
  title TEXT, summary TEXT, analysis TEXT,
  original_text TEXT, image_prompt TEXT,
  has_image   INTEGER DEFAULT 0,      -- 画像実体は _private/img/{sync_id}/{id}.webp
  updated_at  INTEGER NOT NULL,
  deleted     INTEGER DEFAULT 0,      -- 削除は墓標(tombstone)で伝搬
  PRIMARY KEY (sync_id, id)
);
```

### API(`api/entries.php`、会員Cookie+同期Cookie必須)

| メソッド | 動作 |
|---|---|
| `GET ?since=<updated_at>` | 差分一覧(本文+画像URL)。初回は全件 |
| `PUT` | upsert。本文はJSON、画像はbase64(サーバーでファイル保存) |
| `DELETE ?id=` | 墓標化(updated_at更新) |

- 競合解決: **updated_at の新しい方が勝つ**(単純なlast-write-wins。個人日記で同時編集は稀)
- 同期タイミング: 起動時に pull → 保存/削除のたびに push → 失敗時はキューに積み次回再送

### 上限・容量(config.phpで調整可能)

- 画像1枚 ≤ 400KB(超過時はクライアントでcanvas→WebP再圧縮してから送信)
- 1会員あたり記録 2,000件・合計 100MB まで。会員20名 × 100MB = 最大2GBで
  エックスサーバーの容量(300GB〜)には余裕

### プライバシー(夢の内容はセンシティブ)

- 通信はHTTPS、保存先はWeb非公開の `_private/`、アクセスできるのはサーバー管理者(オーナー)のみ
- サーバー上は平文保存とする(初期実装)。クライアント側AES暗号化は「パスフレーズ紛失=全損」
  「サーバー側での画像配信最適化不可」のトレードオフが大きいため初期は見送り。要望が出たら再検討
- **会員への告知文に「夢の記録がコミュニティのサーバーに保存される」ことを明記する**(任意機能とし、
  有効化しない限り従来どおり端末内のみ)
- バックアップ: エックスサーバー自動バックアップ(直近14日)+月1回の手動DBダウンロード

## 5. `.htaccess`(app/直下)

```apache
# _private を全遮断(サブディレクトリ側にも同内容を置く)
RedirectMatch 403 ^/app/_private/

<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "no-referrer"
  Header set X-Frame-Options "SAMEORIGIN"
  # dream-diary: 外部接続先はGemini/AI Horde/HuggingFace Spaceのみ許可
  # (Horde画像の実URLはR2等の場合があるため、導入時に実測して img-src を確定させる)
  # lucid-dream: マイクを自ページのみに限定
  Header set Permissions-Policy "microphone=(self), camera=(), geolocation=()"
</IfModule>
```

CSP(`Content-Security-Policy`)はdream-diary/lucid-dreamで要件が異なるため、各ディレクトリの
`.htaccess` に分けて設定する。導入時にDevToolsで違反が出ないことを確認してから本番適用。

## 6. 併存〜切替の手順

1. Phase 1 を bttp.info に構築し、**github.io側は一切変更しない**(会員影響ゼロ)
2. 新URLでオーナー自身が動作確認(分析・画像生成・バックアップ読み込み)
3. Phase 2(会員ゲート)まで済ませてから会員に告知:
   - 新URL+解錠リンク
   - **移行手順: 旧URLの設定画面で「書き出し」→ 新URLで「読み込み」**(Phase 0の機能)
   - Phase 3が済んでいれば「読み込み後にサーバー保存を有効化」まで案内
4. 移行期間(2〜4週間)は両URL併存。Workerは現状のまま残す
5. 期間終了後: github.io を移転案内スタブに差し替え(ゲームと同じ方式)、Cloudflare Worker/KVを削除

## 7. 決めごと・未決事項

- [ ] `/app/` のまま行くか `app.bttp.info` にするか(§0。現方針は `/app/`)
- [x] lucid-dream の会員ゲート → **実装する**(2026-07-29決定。dream-diaryと同方式、
      index.php経由配信。§3参照。着手はdream-diary移行完了後)
- [ ] Phase 3 のリリース時期(Phase 1-2 と同時か、移行が落ち着いてからか)
- [ ] Horde画像取得先のCSP実測(§5)
