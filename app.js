"use strict";

/* ========== 定数 ========== */
const API_BASE = "https://generativelanguage.googleapis.com/v1beta/models";
const TEXT_MODEL = "gemini-2.5-flash";
const TEXT_MODEL_FALLBACK = "gemini-2.5-flash-lite";
const IMAGE_MODEL_CANDIDATES = ["gemini-3.1-flash-image", "gemini-2.5-flash-image"];
const IMAGE_MODEL_STORAGE = "dreamDiary.imageModel";
const HORDE_KEY_STORAGE = "dreamDiary.hordeKey";
const KEY_STORAGE = "dreamDiary.apiKey";

/* コミュニティ用プロキシ(Cloudflare Worker)のURL。
   設定されている場合、会員はGemini APIキーなしで夢分析を利用できる。
   自分のGeminiキーを設定しているユーザーは直接Geminiを呼ぶ(プロキシを経由しない) */
const PROXY_BASE = "https://yume-nikki-proxy.yumenikki-api.workers.dev";

/* ========== IndexedDB ========== */
const DB_NAME = "dreamDiary";
const STORE = "entries";

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      req.result.createObjectStore(STORE, { keyPath: "id" });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function dbPut(entry) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, "readwrite");
    tx.objectStore(STORE).put(entry);
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

async function dbGetAll() {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const req = db.transaction(STORE).objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function dbGet(id) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const req = db.transaction(STORE).objectStore(STORE).get(id);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function dbDelete(id) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, "readwrite");
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

/* ========== 画面切り替え ========== */
const views = ["view-list", "view-new", "view-detail", "view-settings"];
let currentDetailId = null;
let detailImageUrl = null;

function showView(id) {
  views.forEach(v => { document.getElementById(v).hidden = (v !== id); });
  document.getElementById("btn-new").hidden = (id !== "view-list");
  if (id !== "view-detail" && detailImageUrl) {
    URL.revokeObjectURL(detailImageUrl);
    detailImageUrl = null;
  }
  window.scrollTo(0, 0);
}

/* ========== 一覧 ========== */
let listImageUrls = []; // サムネイル用Object URL。再描画時に解放する

async function renderList() {
  const entries = (await dbGetAll()).sort((a, b) => b.id - a.id);
  const list = document.getElementById("entry-list");
  list.innerHTML = "";
  listImageUrls.forEach(u => URL.revokeObjectURL(u));
  listImageUrls = [];
  document.getElementById("empty-message").hidden = entries.length > 0;

  for (const e of entries) {
    const li = document.createElement("li");
    li.className = "entry-item";

    const img = document.createElement("img");
    img.className = "entry-thumb";
    img.alt = "";
    if (e.image) {
      const url = URL.createObjectURL(e.image);
      listImageUrls.push(url);
      img.src = url;
    }

    const info = document.createElement("div");
    info.className = "entry-info";
    const date = document.createElement("p");
    date.className = "entry-date";
    date.textContent = formatDate(e.id) + (e.pendingJob ? "・🖼 画像作成中" : "");
    const title = document.createElement("p");
    title.className = "entry-title";
    title.textContent = e.title;
    info.append(date, title);

    li.append(img, info);
    li.addEventListener("click", () => openDetail(e.id));
    list.appendChild(li);
  }
}

function formatDate(ts) {
  const d = new Date(ts);
  const w = ["日", "月", "火", "水", "木", "金", "土"][d.getDay()];
  return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日(${w})`;
}

/* ========== 詳細 ========== */
async function openDetail(id) {
  const e = await dbGet(id);
  if (!e) return;
  currentDetailId = id;

  const wrap = document.getElementById("detail-image-wrap");
  const img = document.getElementById("detail-image");
  if (detailImageUrl) {
    // 詳細画面を開いたまま再表示(画像の再生成・自動取り込み)した場合の解放漏れを防ぐ
    URL.revokeObjectURL(detailImageUrl);
    detailImageUrl = null;
  }
  if (e.image) {
    detailImageUrl = URL.createObjectURL(e.image);
    img.src = detailImageUrl;
    wrap.classList.remove("no-image");
  } else {
    img.removeAttribute("src");
    wrap.classList.add("no-image");
  }

  const pending = !!e.pendingJob;
  const regenBtn = document.getElementById("btn-regen-image");
  regenBtn.hidden = false;
  regenBtn.textContent = pending ? "🖼 待たずに作り直す"
    : (e.image ? "🖼 画像を作り直す" : "🖼 画像を生成する");
  document.getElementById("detail-pending").hidden = !pending;

  document.getElementById("detail-date").textContent = formatDate(e.id);
  document.getElementById("detail-title").textContent = e.title;
  document.getElementById("detail-summary").textContent = e.summary;
  document.getElementById("detail-analysis").textContent = e.analysis;
  document.getElementById("detail-original").textContent = e.originalText;
  showView("view-detail");
}

/* ========== Gemini API ========== */
function getApiKey() {
  return localStorage.getItem(KEY_STORAGE) || "";
}

async function callGemini(model, body) {
  const res = await fetch(`${API_BASE}/${model}:generateContent?key=${encodeURIComponent(getApiKey())}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    let msg = `HTTP ${res.status}`;
    try {
      const detail = await res.json();
      if (detail.error && detail.error.message) msg += `: ${detail.error.message}`;
    } catch (_) { /* 本文がJSONでない場合はステータスのみ */ }
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return res.json();
}

/* コミュニティ用プロキシ経由の呼び出し(会員はキー不要) */
async function callProxy(path, text) {
  const res = await fetch(`${PROXY_BASE}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ text }),
  });
  if (!res.ok) {
    const detail = await res.json().catch(() => null);
    const err = new Error(detail && detail.error ? detail.error : `プロキシ HTTP ${res.status}`);
    err.status = res.status;
    throw err;
  }
  return res.json();
}

function usesProxy() {
  return !getApiKey() && !!PROXY_BASE;
}

async function analyzeDream(text) {
  if (usesProxy()) {
    return callProxy("/analyze", text);
  }
  const prompt = `あなたは優しい夢分析の専門家です。以下はユーザーが見た夢の記録です。

---
${text}
---

この夢についてJSONで出力してください:
- title: 夢の内容を表す印象的な短いタイトル(15文字以内、日本語)
- summary: 夢の概要(2〜3文、日本語)
- analysis: 夢分析。夢に登場するシンボルや感情から、心理状態や深層心理をやさしく読み解く(200〜300文字、日本語。断定しすぎず、前向きな締めくくりに)
- imagePrompt: この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプト。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。`;

  const body = {
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: {
      responseMimeType: "application/json",
      responseSchema: {
        type: "OBJECT",
        properties: {
          title: { type: "STRING" },
          summary: { type: "STRING" },
          analysis: { type: "STRING" },
          imagePrompt: { type: "STRING" },
        },
        required: ["title", "summary", "analysis", "imagePrompt"],
      },
    },
  };

  let data;
  try {
    data = await callGemini(TEXT_MODEL, body);
  } catch (e) {
    if (e.status === 429) {
      data = await callGemini(TEXT_MODEL_FALLBACK, body);
    } else {
      throw e;
    }
  }
  return JSON.parse(data.candidates[0].content.parts[0].text);
}

/* このAPIキーで使える画像系モデルの一覧を取得 */
async function listImageModels() {
  try {
    const res = await fetch(`${API_BASE}?pageSize=200&key=${encodeURIComponent(getApiKey())}`);
    if (!res.ok) return [];
    const data = await res.json();
    return (data.models || [])
      .map(m => (m.name || "").replace(/^models\//, ""))
      .filter(n => n.includes("image") && !n.startsWith("imagen") && !n.includes("embedding"));
  } catch (_) {
    return [];
  }
}

function buildImagePrompt(imagePrompt) {
  return `${imagePrompt}\nDreamlike, cinematic, soft ethereal light, high quality.`;
}

/* Geminiで、動作するモデルを自動検出しながら画像を生成。成功したモデルは記憶して次回以降使う */
async function generateImage(imagePrompt) {
  const fullPrompt = buildImagePrompt(imagePrompt);

  let saved = localStorage.getItem(IMAGE_MODEL_STORAGE);
  if (saved === "horde") { // 旧バージョンが保存した値
    localStorage.removeItem(IMAGE_MODEL_STORAGE);
    saved = null;
  }
  if (saved) {
    try {
      return await generateImageGemini(saved, fullPrompt);
    } catch (e) {
      console.warn(`前回のモデル(${saved})が使えなくなったため再検出します`, e);
      localStorage.removeItem(IMAGE_MODEL_STORAGE);
    }
  }

  const discovered = await listImageModels();
  const candidates = [...new Set([...IMAGE_MODEL_CANDIDATES, ...discovered])]
    .filter(m => m !== saved)
    .slice(0, 6);

  let lastError = null;
  for (const model of candidates) {
    try {
      const blob = await generateImageGemini(model, fullPrompt);
      localStorage.setItem(IMAGE_MODEL_STORAGE, model);
      console.info(`画像生成モデルとして ${model} を使用します`);
      return blob;
    } catch (e) {
      console.warn(`${model}: ${e.message}`);
      lastError = e;
    }
  }
  throw lastError || new Error("利用可能な画像生成モデルが見つかりませんでした");
}

/* ========== FLUX.1-schnell(Hugging Face公式Space / ZeroGPU) ==========
   登録・APIキー・カードすべて不要の無料画像生成。数秒〜1分で16:9を生成。
   匿名利用のGPU枠(IP単位)を超えると一時的にエラーになる点だけ注意 */
const FLUX_SPACE_BASE = "https://black-forest-labs-flux-1-schnell.hf.space/gradio_api";

async function generateImageFluxSpace(imagePrompt) {
  // data: [プロンプト, シード, シードをランダムに, 幅, 高さ, ステップ数]
  const post = await fetch(`${FLUX_SPACE_BASE}/call/infer`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ data: [buildImagePrompt(imagePrompt), 0, true, 1024, 576, 4] }),
  });
  if (!post.ok) throw new Error(`FLUX Space HTTP ${post.status}`);
  const { event_id } = await post.json();
  if (!event_id) throw new Error("FLUX Spaceがジョブを受け付けませんでした");

  // 結果はSSEストリームで届く(complete / error イベント)
  const res = await fetch(`${FLUX_SPACE_BASE}/call/infer/${event_id}`);
  if (!res.ok) throw new Error(`FLUX Space HTTP ${res.status}`);
  const reader = res.body.getReader();
  const dec = new TextDecoder();
  let buf = "";
  let result = null;
  let errorEvent = null;
  const deadline = Date.now() + 120000;
  try {
    while (Date.now() < deadline) {
      const chunk = await Promise.race([
        reader.read(),
        new Promise(r => setTimeout(() => r(null), Math.max(deadline - Date.now(), 0))),
      ]);
      if (!chunk) break; // 時間切れ
      if (chunk.value) buf += dec.decode(chunk.value, { stream: true });
      for (const ev of buf.split("\n\n")) {
        const m = ev.match(/^event: (\w+)\ndata: (.*)$/s);
        if (!m) continue;
        if (m[1] === "complete") result = JSON.parse(m[2]);
        else if (m[1] === "error") errorEvent = m[2];
      }
      if (result || errorEvent || chunk.done) break;
    }
  } finally {
    reader.cancel().catch(() => {});
  }

  if (errorEvent) {
    throw new Error(`FLUX Space側でエラー: ${String(errorEvent).slice(0, 150)}(無料GPU枠の一時上限の可能性。しばらく待つと回復します)`);
  }
  if (!result) throw new Error("FLUX Spaceの生成がタイムアウトしました");
  const url = result[0] && result[0].url;
  if (!url) throw new Error("FLUX Spaceの応答に画像がありません");
  const imgRes = await fetch(url);
  if (!imgRes.ok) throw new Error("生成画像の取得に失敗しました");
  return imgRes.blob();
}

/* 速い手段から順に試す: Gemini(無料枠があれば)→ FLUX Space(キー不要)。
   どちらも不可なら例外(呼び出し側でAI Hordeにフォールバック) */
async function generateImageFast(imagePrompt) {
  // 個人のGeminiキーが無ければGemini画像系は必ず失敗するため、無駄な試行をせず直接FLUXへ
  if (!getApiKey()) {
    return generateImageFluxSpace(imagePrompt);
  }
  try {
    return await generateImage(imagePrompt);
  } catch (geminiError) {
    console.warn("Gemini不可のため、無料のFLUX.1-schnell(Hugging Face Space)で生成します", geminiError);
    return generateImageFluxSpace(imagePrompt);
  }
}

/* ========== AI Horde(無料の共有画像生成ネットワーク) ==========
   Geminiの無料枠で画像生成が使えない場合のフォールバック。
   順番待ちが長いため「ジョブ登録 → あとから自動取り込み」方式にする */
const HORDE_BASE = "https://stablehorde.net/api/v2";

function hordeHeaders() {
  return {
    "Content-Type": "application/json",
    "apikey": localStorage.getItem(HORDE_KEY_STORAGE) || "0000000000", // 未登録は匿名キー
    "Client-Agent": "yume-nikki-dream-diary:1.0:github",
  };
}

async function submitHordeJob(imagePrompt) {
  // 「###」以降はネガティブプロンプト(避けたい要素)
  const prompt = buildImagePrompt(imagePrompt).slice(0, 800) +
    " ### blurry, lowres, worst quality, bad anatomy, deformed, extra limbs, text, watermark, signature";
  // 匿名利用で許される計算コストの上限は混雑状況で変動するため、
  // 高品質な設定から順に、通るまで軽い設定に落として再試行する
  const paramLadder = [
    { width: 576, height: 320, steps: 24, cfg_scale: 6, n: 1, post_processing: ["RealESRGAN_x4plus"] },
    { width: 576, height: 320, steps: 24, cfg_scale: 6, n: 1 },
    { width: 448, height: 256, steps: 20, cfg_scale: 6, n: 1 },
  ];

  let lastError = null;
  for (const params of paramLadder) {
    const res = await fetch(`${HORDE_BASE}/generate/async`, {
      method: "POST",
      headers: hordeHeaders(),
      body: JSON.stringify({ prompt, params, nsfw: false, censor_nsfw: true, shared: false, r2: false }),
    });
    if (res.ok) {
      const data = await res.json();
      return { id: data.id, submittedAt: Date.now() };
    }
    const detail = await res.json().catch(() => null);
    const msg = detail && detail.message ? detail.message : "";
    lastError = new Error(`AI Horde HTTP ${res.status}${msg ? `: ${msg}` : ""}`);
    // kudos不足(混雑による匿名制限)のときだけ、次の軽い設定を試す
    if (!(res.status === 403 && /kudos/i.test(msg))) throw lastError;
  }
  throw new Error(
    `${lastError.message}\n\n※ 現在ネットワークが混雑していて匿名利用が制限されています。` +
    "設定画面から AI Horde の無料登録キーを設定すると、混雑時でも生成できるようになります。"
  );
}

async function checkHordeJob(id) {
  const res = await fetch(`${HORDE_BASE}/generate/check/${id}`);
  if (res.status === 404) return { gone: true };
  if (!res.ok) throw new Error(`AI Horde HTTP ${res.status}`);
  return res.json();
}

async function fetchHordeResult(id) {
  const res = await fetch(`${HORDE_BASE}/generate/status/${id}`, { headers: { apikey: hordeHeaders().apikey } });
  if (!res.ok) return null;
  const status = await res.json();
  const gen = status.generations && status.generations[0];
  if (!gen || !gen.img || gen.censored) return null;
  if (/^https?:/.test(gen.img)) {
    const imgRes = await fetch(gen.img);
    return imgRes.ok ? imgRes.blob() : null;
  }
  const bin = atob(gen.img);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Blob([bytes], { type: "image/webp" });
}

/* 生成待ちジョブを定期確認し、完成した画像を記録に取り込む */
async function pollPendingJobs() {
  let entries;
  try {
    entries = (await dbGetAll()).filter(e => e.pendingJob);
  } catch (_) {
    return;
  }
  for (const e of entries) {
    try {
      const chk = await checkHordeJob(e.pendingJob.id);
      if (chk.gone || chk.faulted) {
        delete e.pendingJob;
        await dbPut(e);
      } else if (chk.done) {
        const blob = await fetchHordeResult(e.pendingJob.id);
        if (blob) e.image = blob;
        delete e.pendingJob;
        await dbPut(e);
      } else if (Date.now() - (e.pendingJob.submittedAt || 0) > 90 * 60 * 1000) {
        delete e.pendingJob; // 90分たっても完成しないジョブは諦める(再生成できる)
        await dbPut(e);
      } else {
        updatePendingNote(e, chk); // まだ順番待ち: 進捗を表示に反映
        continue;
      }
      await refreshAfterImageUpdate(e.id);
    } catch (err) {
      console.warn("生成待ちジョブの確認に失敗", err);
    }
  }
}

/* 詳細画面を開いている記録の順番待ち状況を「作成中」表示に反映する */
function updatePendingNote(entry, chk) {
  if (document.getElementById("view-detail").hidden || currentDetailId !== entry.id) return;
  const note = document.getElementById("detail-pending");
  if (note.hidden) return;
  const mins = Math.max(1, Math.ceil((chk.wait_time || 0) / 60));
  note.textContent = chk.queue_position > 0
    ? `🖼 画像を作成中です(順番待ち: ${chk.queue_position}番目・完成まで約${mins}分)。完成すると自動でここに表示されます。そのままお待ちください。`
    : `🖼 画像を生成しています(完成まで約${mins}分)。完成すると自動でここに表示されます。`;
}

async function refreshAfterImageUpdate(id) {
  if (!document.getElementById("view-list").hidden) {
    await renderList();
  } else if (!document.getElementById("view-detail").hidden && currentDetailId === id) {
    await openDetail(id);
  }
}

async function generateImageGemini(model, prompt) {
  const body = {
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: {
      responseModalities: ["TEXT", "IMAGE"],
      imageConfig: { aspectRatio: "16:9" },
    },
  };
  let data;
  try {
    data = await callGemini(model, body);
  } catch (e) {
    // 一部モデルはimageConfig非対応のため、外して再試行(構図はプロンプトで指示)
    if (e.status !== 400) throw e;
    data = await callGemini(model, {
      contents: [{ parts: [{ text: `${prompt}\nWide 16:9 cinematic aspect ratio.` }] }],
      generationConfig: { responseModalities: ["TEXT", "IMAGE"] },
    });
  }
  const part = data.candidates[0].content.parts.find(p => p.inlineData);
  if (!part) throw new Error("no image in response");

  const bin = atob(part.inlineData.data);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Blob([bytes], { type: part.inlineData.mimeType || "image/png" });
}

/* ========== 記録の保存フロー ========== */
async function saveDream() {
  const text = document.getElementById("dream-input").value.trim();
  if (!text) {
    alert("夢の内容を入力してください。");
    return;
  }
  if (!getApiKey() && !PROXY_BASE) {
    alert("先に設定画面でGemini APIキーを登録してください。");
    showView("view-settings");
    return;
  }

  stopMic();
  const progress = document.getElementById("progress");
  const progressText = document.getElementById("progress-text");
  const btnSave = document.getElementById("btn-save");
  progress.hidden = false;
  btnSave.disabled = true;

  try {
    progressText.textContent = "夢を分析しています…";
    const result = await analyzeDream(text);

    progressText.textContent = "夢のシーンを画像にしています…";
    let image = null;
    let pendingJob = null;
    let imageError = null;
    let fallbackReason = "";
    try {
      image = await generateImageFast(result.imagePrompt);
    } catch (e) {
      console.warn("Gemini/FLUX Spaceでの画像生成に失敗。無料のAI Hordeに依頼します", e);
      fallbackReason = e.message;
      try {
        pendingJob = await submitHordeJob(result.imagePrompt);
      } catch (e2) {
        imageError = e2;
        console.warn("AI Hordeへの依頼にも失敗しました(記録は保存されます)", e2);
      }
    }

    const entry = {
      id: Date.now(),
      title: result.title,
      summary: result.summary,
      analysis: result.analysis,
      originalText: text,
      imagePrompt: result.imagePrompt,
      image,
    };
    if (pendingJob) entry.pendingJob = pendingJob;
    await dbPut(entry);

    document.getElementById("dream-input").value = "";
    await renderList();
    showView("view-list");

    if (pendingJob) {
      setTimeout(pollPendingJobs, 3000); // すぐに順番待ち状況を表示できるようにする
      alert("記録を保存しました。\n\n画像は無料の生成ネットワーク(AI Horde)で作成中です。完成すると自動で追加されます(混雑状況により数分〜数十分。アプリを開いている間に取り込まれます)。"
        + (fallbackReason ? `\n\nAI Hordeになった理由: ${fallbackReason.slice(0, 150)}` : ""));
    } else if (imageError) {
      alert(`記録は保存しましたが、画像の生成に失敗しました。\n\n理由: ${imageError.message}\n\n詳細画面の「🖼 画像を生成する」からやり直せます。`);
    }
  } catch (e) {
    console.error(e);
    if (e.status === 400 || e.status === 403) {
      alert("APIキーが正しくないようです。設定画面で確認してください。");
    } else if (e.status === 429) {
      alert("本日の無料利用枠の上限に達した可能性があります。少し時間をおいて試してください。");
    } else {
      alert("記録に失敗しました。通信環境を確認してもう一度試してください。");
    }
  } finally {
    progress.hidden = true;
    btnSave.disabled = false;
  }
}

/* ========== 画像の再生成 ========== */

/* 英語の画像プロンプトを確実に用意する。
   初期バージョンで保存された記録には無いため、Geminiで作成して保存し直す
   (日本語のままAI Hordeに送ると、内容と無関係な画像になってしまう) */
async function ensureImagePrompt(entry) {
  if (entry.imagePrompt) return entry.imagePrompt;

  if (usesProxy()) {
    try {
      const data = await callProxy("/image-prompt", entry.originalText || entry.summary);
      entry.imagePrompt = data.imagePrompt;
      await dbPut(entry);
      return entry.imagePrompt;
    } catch (e) {
      console.warn("英語プロンプトの生成に失敗。簡易プロンプトで代用します", e);
      return `A dreamlike cinematic scene based on this dream: ${entry.summary} The people are Japanese and the setting is Japan unless otherwise specified.`;
    }
  }

  const req = `以下はユーザーが見た夢の記録です。この夢の最も印象的なワンシーンを画像生成AIで再現するための詳細な英語プロンプトを1つ作ってください。幻想的で映画のワンシーンのような雰囲気。夢の中で特に指定がない限り、登場人物は日本人(Japanese)、舞台は日本(Japan)とすること。出力は英語プロンプトのみ(前置きや説明は不要)。

---
${entry.originalText || entry.summary}`;

  try {
    const body = { contents: [{ parts: [{ text: req }] }] };
    let data;
    try {
      data = await callGemini(TEXT_MODEL, body);
    } catch (e) {
      if (e.status === 429) data = await callGemini(TEXT_MODEL_FALLBACK, body);
      else throw e;
    }
    entry.imagePrompt = data.candidates[0].content.parts[0].text.trim();
    await dbPut(entry);
    return entry.imagePrompt;
  } catch (e) {
    console.warn("英語プロンプトの生成に失敗。簡易プロンプトで代用します", e);
    return `A dreamlike cinematic scene based on this dream: ${entry.summary} The people are Japanese and the setting is Japan unless otherwise specified.`;
  }
}
async function regenerateDetailImage() {
  if (!currentDetailId) return;
  if (!getApiKey() && !PROXY_BASE) {
    alert("先に設定画面でGemini APIキーを登録してください。");
    showView("view-settings");
    return;
  }
  const entry = await dbGet(currentDetailId);
  if (!entry) return;

  if (entry.pendingJob && !confirm(
    "画像はいま作成中です。作り直すと順番待ちの最後尾に並び直すため、かえって遅くなることがあります。\n\nそれでも作り直しますか?"
  )) {
    return;
  }

  const btn = document.getElementById("btn-regen-image");
  btn.disabled = true;
  btn.textContent = "🖼 生成中…(数分かかることがあります)";

  // 順番待ち中のジョブがあれば取り消して、現在の設定(登録キーなど)で作り直す
  if (entry.pendingJob) {
    fetch(`${HORDE_BASE}/generate/status/${entry.pendingJob.id}`, {
      method: "DELETE",
      headers: hordeHeaders(),
    }).catch(() => {});
    delete entry.pendingJob;
    await dbPut(entry);
  }

  const prompt = await ensureImagePrompt(entry); // 失敗時は内部で簡易プロンプトに代替
  try {
    entry.image = await generateImageFast(prompt);
    await dbPut(entry);
  } catch (e) {
    console.warn("Gemini/FLUX Spaceでの画像生成に失敗。無料のAI Hordeに依頼します", e);
    try {
      entry.pendingJob = await submitHordeJob(prompt);
      await dbPut(entry);
      setTimeout(pollPendingJobs, 3000); // すぐに順番待ち状況を表示できるようにする
    } catch (e2) {
      console.error(e2);
      alert(`画像の生成に失敗しました。\n\n理由: ${e2.message}`);
    }
  } finally {
    btn.disabled = false;
    await openDetail(entry.id); // 最新の状態(画像/作成中/ボタン表示)に更新
  }
}

/* ========== 接続テスト ========== */
async function runConnectionTest() {
  const out = document.getElementById("test-result");
  const inputKey = document.getElementById("api-key-input").value.trim();
  if (inputKey) localStorage.setItem(KEY_STORAGE, inputKey);
  if (!getApiKey() && !PROXY_BASE) {
    out.textContent = "先にAPIキーを入力してください。";
    return;
  }

  const btn = document.getElementById("btn-test");
  btn.disabled = true;
  const lines = [];

  out.textContent = "1/2 夢分析(テキスト)をテスト中…";
  if (usesProxy()) {
    try {
      await callProxy("/image-prompt", "空を飛ぶ夢");
      lines.push("✅ 夢分析 (コミュニティ共通・キー不要): OK");
    } catch (e) {
      lines.push(`❌ 夢分析 (コミュニティ共通): ${e.message}`);
    }
  } else {
    try {
      await callGemini(TEXT_MODEL, { contents: [{ parts: [{ text: "「こんにちは」とだけ返してください。" }] }] });
      lines.push(`✅ 夢分析 (${TEXT_MODEL}): OK`);
    } catch (e) {
      lines.push(`❌ 夢分析 (${TEXT_MODEL}): ${e.message}`);
    }
  }

  out.textContent = lines.join("\n") + "\n2/2 画像生成をテスト中…(使えるモデルを自動検出しています。1分ほどかかることがあります)";
  localStorage.removeItem(IMAGE_MODEL_STORAGE); // 毎回ゼロから検出し直す
  try {
    if (!getApiKey()) throw new Error("Gemini APIキー未設定(キー不要モードのため試行せず)");
    await generateImage("A simple watercolor illustration of a crescent moon in a night sky");
    lines.push(`✅ 画像生成 (Gemini): OK(使用モデル: ${localStorage.getItem(IMAGE_MODEL_STORAGE)})`);
  } catch (e) {
    lines.push("⚠️ Geminiの画像生成はこのキーの無料枠では使えません。");
    lines.push(`(Geminiのエラー: ${e.message})`);
    out.textContent = lines.join("\n") + "\n無料のFLUX.1-schnell(Hugging Face Space)をテスト中…(最大2分)";
    try {
      await generateImageFluxSpace("A simple watercolor illustration of a crescent moon in a night sky");
      lines.push("✅ 画像生成 (FLUX.1-schnell / キー不要・無料): OK — 数秒〜1分で生成されます");
    } catch (e3) {
      lines.push(`❌ FLUX.1-schnell: ${e3.message}`);
      lines.push("→ 画像は無料の共有ネットワーク「AI Horde」で自動生成されます(順番待ちあり・完成後に自動で記録へ追加)。");
      lines.push(localStorage.getItem(HORDE_KEY_STORAGE)
        ? "AI Horde: 登録キーを使用中(順番待ちが短縮されます)"
        : "AI Horde: 匿名で利用中。無料登録キーを設定すると順番待ちが大幅に短くなります。");
    }
  }

  out.textContent = lines.join("\n");
  btn.disabled = false;
}

/* ========== 音声入力 ========== */
let recognition = null;
let recognizing = false;
let baseText = "";

function setupMic() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const btn = document.getElementById("btn-mic");
  const status = document.getElementById("mic-status");

  if (!SR) {
    btn.disabled = true;
    status.textContent = "このブラウザは音声入力に未対応です";
    return;
  }

  recognition = new SR();
  recognition.lang = "ja-JP";
  recognition.continuous = true;
  recognition.interimResults = true;

  recognition.onresult = (ev) => {
    let finalText = "";
    let interim = "";
    for (const r of ev.results) {
      if (r.isFinal) finalText += r[0].transcript;
      else interim += r[0].transcript;
    }
    document.getElementById("dream-input").value = baseText + finalText + interim;
  };

  recognition.onend = () => {
    recognizing = false;
    btn.classList.remove("recording");
    btn.textContent = "🎤 マイクで話す";
    status.textContent = "";
  };

  recognition.onerror = (ev) => {
    if (ev.error === "not-allowed") {
      status.textContent = "マイクの使用が許可されていません";
    }
  };

  btn.addEventListener("click", () => {
    if (recognizing) {
      stopMic();
    } else {
      const input = document.getElementById("dream-input");
      baseText = input.value ? input.value + " " : "";
      recognition.start();
      recognizing = true;
      btn.classList.add("recording");
      btn.textContent = "⏹ 停止";
      status.textContent = "聞き取り中…";
    }
  });
}

function stopMic() {
  if (recognition && recognizing) recognition.stop();
}

/* ========== 設定 ========== */
function setupSettings() {
  document.getElementById("btn-settings").addEventListener("click", () => {
    document.getElementById("api-key-input").value = getApiKey();
    document.getElementById("horde-key-input").value = localStorage.getItem(HORDE_KEY_STORAGE) || "";
    document.getElementById("settings-saved").hidden = true;
    showView("view-settings");
  });
  document.getElementById("btn-save-key").addEventListener("click", () => {
    localStorage.setItem(KEY_STORAGE, document.getElementById("api-key-input").value.trim());
    const hordeKey = document.getElementById("horde-key-input").value.trim();
    if (hordeKey) localStorage.setItem(HORDE_KEY_STORAGE, hordeKey);
    else localStorage.removeItem(HORDE_KEY_STORAGE);
    document.getElementById("settings-saved").hidden = false;
  });
  document.getElementById("btn-back-settings").addEventListener("click", () => showView("view-list"));
  document.getElementById("btn-test").addEventListener("click", runConnectionTest);
}

/* ========== 初期化 ========== */
document.addEventListener("DOMContentLoaded", async () => {
  document.getElementById("btn-new").addEventListener("click", () => showView("view-new"));
  document.getElementById("btn-cancel-new").addEventListener("click", () => {
    stopMic();
    showView("view-list");
  });
  document.getElementById("btn-save").addEventListener("click", saveDream);
  document.getElementById("btn-back").addEventListener("click", async () => {
    await renderList();
    showView("view-list");
  });
  document.getElementById("btn-regen-image").addEventListener("click", regenerateDetailImage);
  document.getElementById("btn-delete").addEventListener("click", async () => {
    if (currentDetailId && confirm("この夢の記録を削除しますか?")) {
      await dbDelete(currentDetailId);
      await renderList();
      showView("view-list");
    }
  });

  setupMic();
  setupSettings();
  await renderList();
  showView("view-list");

  // 生成待ちの画像があれば定期的に確認して取り込む
  pollPendingJobs();
  setInterval(pollPendingJobs, 30000);
});
