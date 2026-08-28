/**
 * 夢日記 コミュニティ用 Gemini プロキシ(Cloudflare Worker)
 *
 * - 会員はAPIキー不要。GeminiのキーはこのWorkerのSecret(GEMINI_API_KEY)にのみ保存される
 * - エンドポイントを夢分析用途に固定しているため、汎用のGemini中継としては悪用できない
 * - 許可したオリジン(公開サイト)以外からのリクエストは拒否する
 *
 * セットアップ手順は リポジトリの DEVELOPMENT.md「コミュニティ運用(キー不要化)」を参照
 */

const ALLOWED_ORIGINS = [
  "https://bttp1605.github.io",
  "http://localhost:4173", // ローカル検証用
];

const API_BASE = "https://generativelanguage.googleapis.com/v1beta/models";
const TEXT_MODEL = "gemini-2.5-flash";
const TEXT_MODEL_FALLBACK = "gemini-3.5-flash-lite";

export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const allowed = ALLOWED_ORIGINS.includes(origin);
    const cors = {
      "Access-Control-Allow-Origin": allowed ? origin : ALLOWED_ORIGINS[0],
      "Access-Control-Allow-Methods": "POST, OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type",
    };

    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: cors });
    }
    if (!allowed) {
      return jsonRes({ error: "このアプリ以外からは利用できません" }, 403, cors);
    }
    if (request.method !== "POST") {
      return jsonRes({ error: "not found" }, 404, cors);
    }

    // レート制限(KVバインディング RATE_LIMIT があるときのみ有効)。
    // Originはブラウザ以外から偽装できるため、タダ乗りされても1日の被害を上限で抑える
    if (env.RATE_LIMIT) {
      const limited = await checkRateLimit(env, request);
      if (limited) return jsonRes({ error: limited }, 429, cors);
    }

    let body;
    try {
      body = await request.json();
    } catch (_) {
      return jsonRes({ error: "bad request" }, 400, cors);
    }
    const text = String(body.text || "").slice(0, 4000).trim();
    if (!text) {
      return jsonRes({ error: "text required" }, 400, cors);
    }

    const path = new URL(request.url).pathname;
    try {
      if (path === "/analyze") {
        const data = await callGemini(env, analyzePrompt(text), ANALYZE_SCHEMA);
        return jsonRes(JSON.parse(data.candidates[0].content.parts[0].text), 200, cors);
      }
      if (path === "/image-prompt") {
        const data = await callGemini(env, imagePromptPrompt(text), null);
        return jsonRes({ imagePrompt: data.candidates[0].content.parts[0].text.trim() }, 200, cors);
      }
      return jsonRes({ error: "not found" }, 404, cors);
    } catch (e) {
      return jsonRes({ error: e.message }, e.status === 429 ? 429 : 502, cors);
    }
  },
};

/* ---------- レート制限(1日あたり) ---------- */

const DAILY_LIMIT_PER_IP = 40;   // 1人(IP)あたり
const DAILY_LIMIT_GLOBAL = 400;  // コミュニティ全体

async function checkRateLimit(env, request) {
  try {
    const day = new Date().toISOString().slice(0, 10);
    const ip = request.headers.get("CF-Connecting-IP") || "unknown";
    const ipKey = `ip:${day}:${ip}`;
    const globalKey = `all:${day}`;
    const [ipCount, globalCount] = await Promise.all([
      env.RATE_LIMIT.get(ipKey),
      env.RATE_LIMIT.get(globalKey),
    ]);
    if (Number(globalCount || 0) >= DAILY_LIMIT_GLOBAL) {
      return "本日のコミュニティ全体の利用上限に達しました。明日また試してください";
    }
    if (Number(ipCount || 0) >= DAILY_LIMIT_PER_IP) {
      return "本日の利用上限に達しました。明日また試してください";
    }
    await Promise.all([
      env.RATE_LIMIT.put(ipKey, String(Number(ipCount || 0) + 1), { expirationTtl: 172800 }),
      env.RATE_LIMIT.put(globalKey, String(Number(globalCount || 0) + 1), { expirationTtl: 172800 }),
    ]);
  } catch (_) {
    // KVの一時的な障害時は制限なしで続行(会員のサービス停止の方を避ける)
  }
  return null;
}

function jsonRes(obj, status, cors) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", ...cors },
  });
}

/* ---------- プロンプト(アプリ本体と同内容をサーバー側に固定) ---------- */

function analyzePrompt(text) {
  return `あなたは優しい夢分析の専門家です。以下はユーザーが見た夢の記録です。

---
${text}
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

  禁止: 受診・服薬・健康の判断、金銭の支出や投資、退職・離婚・絶縁などの重大な決断、他者への詰問。夢の感情が強くつらいものだった場合は rest を選ぶこと。`;
}

const ANALYZE_SCHEMA = {
  type: "OBJECT",
  properties: {
    title: { type: "STRING" },
    summary: { type: "STRING" },
    analysis: { type: "STRING" },
    imagePrompt: { type: "STRING" },
    // categoryを先頭に置くこと。actionを先に書かせると、モデルが行動を決めてから
    // 後付けでcategoryを選ぶため型から選ばせる設計が効かず、提案がwriteに偏る(実測)
    todayStep: {
      type: "OBJECT",
      properties: {
        category: { type: "STRING", enum: ["contact", "tidy", "move", "look", "rest", "close", "write"] },
        action: { type: "STRING" },
        because: { type: "STRING" },
        minutes: { type: "INTEGER" },
      },
      required: ["category", "action", "because", "minutes"],
    },
  },
  required: ["title", "summary", "analysis", "imagePrompt", "todayStep"],
};

function imagePromptPrompt(text) {
  return `以下はユーザーが見た夢の記録です。この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプトを1つ作ってください。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。出力は英語プロンプトのみ(前置きや説明は不要)。

---
${text}`;
}

/* ---------- Gemini呼び出し(429時は軽量モデルへフォールバック) ---------- */

async function callGemini(env, prompt, schema) {
  const body = { contents: [{ parts: [{ text: prompt }] }] };
  if (schema) {
    body.generationConfig = { responseMimeType: "application/json", responseSchema: schema };
  }
  let res = await geminiFetch(env, TEXT_MODEL, body);
  if (res.status === 429) {
    res = await geminiFetch(env, TEXT_MODEL_FALLBACK, body);
  }
  if (!res.ok) {
    let msg = `Gemini HTTP ${res.status}`;
    try {
      const d = await res.json();
      if (d.error && d.error.message) msg += `: ${d.error.message}`;
    } catch (_) { /* 本文がJSONでない場合はステータスのみ */ }
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return res.json();
}

function geminiFetch(env, model, body) {
  return fetch(`${API_BASE}/${model}:generateContent?key=${env.GEMINI_API_KEY}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}
