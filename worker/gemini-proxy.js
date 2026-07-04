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
const TEXT_MODEL_FALLBACK = "gemini-2.5-flash-lite";

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
- imagePrompt: この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプト。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。`;
}

const ANALYZE_SCHEMA = {
  type: "OBJECT",
  properties: {
    title: { type: "STRING" },
    summary: { type: "STRING" },
    analysis: { type: "STRING" },
    imagePrompt: { type: "STRING" },
  },
  required: ["title", "summary", "analysis", "imagePrompt"],
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
