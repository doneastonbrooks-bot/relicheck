// ReliCheck Open-Ended Summary
// -------------------------------------------------------------------
// Universal descriptive layer for free-text fields. Reads the dataset
// produced by Evidence Intake (variables tagged types: ['open']) and
// emits, per question:
//   - response rate, completion, answered count
//   - mean / median / min / max word counts
//   - length distribution (single / short / medium / long buckets)
//   - top unigrams and top bigrams (stopword-filtered)
//   - 3 representative sample quotes
//   - one plain-language interpretation line
// Plus an aggregate roll-up across all open fields.
//
// Theme extraction, codebooks, and inter-coder agreement live in the
// MM Studio's Theme Analysis app (this is the universal descriptive
// layer, not the deeper qualitative pass).
//
// Dataset shape this consumes (same shape strength-index uses):
//   {
//     source: 'Workplace Equity Survey, 2026',
//     variables: [
//       { name: 'openended_response', types: ['open'],
//         values: ['Good support...', 'Pace is fast...', ...] },
//       ...
//     ],
//     rowCount: 8
//   }
//
// Config (window.OPEN_ENDED_CONFIG, set by the mount page) supplies
// the per-studio language (e.g. 'open-ended responses' for Survey,
// 'comments' for 360, 'constructed responses' for TIA).

(function () {
  'use strict';

  // ---------- Resolve the dataset ----------
  // Priority: a dataset uploaded via Evidence Intake (project-scoped
  // localStorage key) beats the inline sample.
  let dataset = window.OPEN_ENDED_DATASET;
  let datasetSource = 'sample';
  const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
  try {
    const stored = window.localStorage.getItem('relicheck.dataset.' + projectId);
    if (stored) {
      const parsed = JSON.parse(stored);
      if (parsed && parsed.payload && parsed.payload.dataset) {
        dataset = parsed.payload.dataset;
        datasetSource = 'uploaded';
      }
    }
  } catch (e) {
    console.warn('Open-Ended Summary: could not read dataset from localStorage:', e);
  }
  window.OPEN_ENDED_DATASET_RESOLVED = dataset;
  window.OPEN_ENDED_DATASET_SOURCE   = datasetSource;

  if (!dataset || !Array.isArray(dataset.variables)) {
    console.warn('Open-Ended Summary: no dataset available');
    showEmpty('No dataset is available yet. Run Evidence Intake first.');
    return;
  }

  // ---------- Resolve the per-studio config ----------
  // Defaults are Survey-flavored language. Mount pages override via
  // window.OPEN_ENDED_CONFIG to retint for MM / TIA / 360.
  const CONFIG = Object.assign({
    kind_label_long:   'Open-ended responses',
    kind_label_short:  'Open-ended',
    field_noun:        'open-ended field',
    field_noun_plural: 'open-ended fields',
    answer_noun:       'response',
    answer_noun_plural:'responses',
    interp_lead_strong:'The qualitative picture is rich.',
    interp_lead_weak:  'The qualitative picture is thin.',
    interp_lead_none:  'There are no open-ended fields in this dataset.',
  }, window.OPEN_ENDED_CONFIG || {});

  // ---------- Filter to open variables ----------
  const openVars = dataset.variables.filter(v => Array.isArray(v.types) && v.types.indexOf('open') !== -1);
  const rowCount = dataset.rowCount || (dataset.variables[0] ? dataset.variables[0].values.length : 0);

  if (!openVars.length) {
    showEmpty();
    setAggregateText('Aggregate', 'No open-ended fields in this dataset.');
    setText('oeStatFields', '0');
    setText('oeStatAnswers', '0');
    setText('oeStatResponseRate', '—');
    setText('oeStatAvgWords', '—');
    setText('oeStatSubstantive', '0');
    setInterp(CONFIG.interp_lead_none, '');
    exposeAppState({
      perQuestion: [],
      aggregate: { fields: 0, answers: 0, responseRate: 0, avgWords: 0, substantive: 0 },
      signals:   { response_rate: 0, mean_words: 0, substantive_count: 0 },
      verdict:   'No open-ended data',
    });
    return;
  }

  // ---------- Stopword list (English, ~70 most common) ----------
  // Small intentionally. Open-ended answers are usually short; a heavier
  // list strips too much signal. Numbers and punctuation are filtered
  // separately during tokenization.
  const STOP = new Set([
    'a','an','and','or','but','if','then','the','this','that','these','those',
    'is','are','was','were','be','been','being','am',
    'i','you','he','she','it','we','they','me','him','her','us','them',
    'my','your','his','its','our','their','mine','yours','ours','theirs',
    'of','in','on','at','to','for','from','by','with','about','as','into','through',
    'do','does','did','done','doing',
    'have','has','had','having',
    'will','would','could','should','can','cant','cannot',
    'not','no','nor','too','very','just','also','only','really','quite',
    'so','than','because','while','when','where','why','how','what','who','whom',
    'there','here','out','up','down','off','over','under','again','more','most','some','any','all','both','each','few','other','such',
  ]);

  // ---------- Tokenization ----------
  function tokenize(text) {
    if (text == null) return [];
    // Lowercase, replace non-letter/digit with spaces, collapse spaces.
    return String(text)
      .toLowerCase()
      .replace(/[^a-z0-9'\-\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .split(' ')
      .filter(w => w.length > 1 && !STOP.has(w) && !/^[0-9'\-]+$/.test(w));
  }
  function tokenizeWithStops(text) {
    // For bigrams: keep stopwords in position but exclude phrases composed
    // entirely of stopwords. This way 'work load' beats 'in the'.
    if (text == null) return [];
    return String(text)
      .toLowerCase()
      .replace(/[^a-z0-9'\-\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .split(' ')
      .filter(w => w.length > 0 && !/^[0-9'\-]+$/.test(w));
  }

  function isNonEmpty(val) {
    return val != null && String(val).trim().length > 0;
  }
  function wordCount(text) {
    if (!isNonEmpty(text)) return 0;
    return String(text).trim().split(/\s+/).length;
  }
  function median(arr) {
    if (!arr.length) return 0;
    const s = arr.slice().sort((a, b) => a - b);
    const mid = Math.floor(s.length / 2);
    return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
  }
  function pickSampleQuotes(answers) {
    // Strategy: one short, one medium, one long when available. Avoid
    // single-word answers in samples unless that's all we have.
    const nonEmpty = answers.filter(a => isNonEmpty(a)).map(a => String(a).trim());
    if (!nonEmpty.length) return [];
    const withLen = nonEmpty.map(t => ({ t: t, w: wordCount(t) })).sort((a, b) => a.w - b.w);
    const short  = withLen.find(x => x.w >= 2 && x.w <= 5)   || withLen.find(x => x.w >= 1);
    const medium = withLen.find(x => x.w >= 6 && x.w <= 15) || null;
    const long   = withLen.slice().reverse().find(x => x.w >= 12) || null;
    const picks = [];
    [short, medium, long].forEach(x => {
      if (x && picks.indexOf(x.t) === -1) picks.push(x.t);
    });
    // Top up to 3 with longest available, no duplicates.
    let i = withLen.length - 1;
    while (picks.length < 3 && i >= 0) {
      if (picks.indexOf(withLen[i].t) === -1) picks.push(withLen[i].t);
      i--;
    }
    return picks;
  }

  // ---------- Per-question analysis ----------
  function analyzeQuestion(v) {
    const values = v.values || [];
    const totalRows = values.length || rowCount;
    const nonEmpty  = values.filter(isNonEmpty).map(x => String(x).trim());
    const answered  = nonEmpty.length;
    const responseRate = totalRows ? answered / totalRows : 0;

    const lengths = nonEmpty.map(wordCount);
    const meanWords   = answered ? lengths.reduce((s, n) => s + n, 0) / answered : 0;
    const medWords    = median(lengths);
    const minWords    = answered ? Math.min.apply(null, lengths) : 0;
    const maxWords    = answered ? Math.max.apply(null, lengths) : 0;

    const buckets = { single: 0, short: 0, medium: 0, long: 0 };
    lengths.forEach(w => {
      if (w <= 1)      buckets.single++;
      else if (w <= 10) buckets.short++;
      else if (w <= 25) buckets.medium++;
      else              buckets.long++;
    });

    // Unigrams
    const unigramCounts = new Map();
    nonEmpty.forEach(t => {
      tokenize(t).forEach(w => {
        unigramCounts.set(w, (unigramCounts.get(w) || 0) + 1);
      });
    });
    const topUnigrams = Array.from(unigramCounts.entries())
      .filter(([, c]) => c >= 1)
      .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
      .slice(0, 8)
      .map(([w, c]) => ({ word: w, count: c }));

    // Bigrams
    const bigramCounts = new Map();
    nonEmpty.forEach(t => {
      const toks = tokenizeWithStops(t);
      for (let i = 0; i < toks.length - 1; i++) {
        const a = toks[i], b = toks[i + 1];
        if (STOP.has(a) && STOP.has(b)) continue;
        const phrase = a + ' ' + b;
        bigramCounts.set(phrase, (bigramCounts.get(phrase) || 0) + 1);
      }
    });
    const topBigrams = Array.from(bigramCounts.entries())
      .filter(([, c]) => c >= 2)
      .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
      .slice(0, 6)
      .map(([p, c]) => ({ phrase: p, count: c }));

    const sampleQuotes = pickSampleQuotes(values);
    const substantive  = nonEmpty.filter(t => t.length >= 10).length;

    // Per-question interpretation (one sentence)
    let interp;
    if (!answered) {
      interp = 'No one answered this question.';
    } else if (responseRate < 0.30) {
      interp = 'Only ' + Math.round(responseRate * 100) + '% of respondents answered. The question may be optional, late in the survey, or unclear.';
    } else if (meanWords < 3) {
      interp = 'Most answers are very short (mean ' + meanWords.toFixed(1) + ' words). Useful for quick signal but limited for thematic analysis.';
    } else if (meanWords >= 15) {
      interp = 'Rich detail (mean ' + meanWords.toFixed(1) + ' words across ' + answered + ' answers). Strong base for theme analysis.';
    } else if (responseRate >= 0.70) {
      interp = 'High response rate (' + Math.round(responseRate * 100) + '%) at an average of ' + meanWords.toFixed(1) + ' words. A workable base for coding.';
    } else {
      interp = Math.round(responseRate * 100) + '% answered with an average of ' + meanWords.toFixed(1) + ' words. Read in context with other items.';
    }

    return {
      name:         v.name,
      label:        v.text || titleize(v.name),
      totalRows:    totalRows,
      answered:     answered,
      responseRate: responseRate,
      meanWords:    meanWords,
      medianWords:  medWords,
      minWords:     minWords,
      maxWords:     maxWords,
      buckets:      buckets,
      topUnigrams:  topUnigrams,
      topBigrams:   topBigrams,
      sampleQuotes: sampleQuotes,
      substantive:  substantive,
      interp:       interp,
    };
  }

  function titleize(name) {
    return String(name)
      .replace(/[_\-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b([a-z])/g, m => m.toUpperCase());
  }

  // ---------- Run analysis ----------
  const perQuestion = openVars.map(analyzeQuestion);

  // Aggregate signals
  const totalAnswers = perQuestion.reduce((s, q) => s + q.answered, 0);
  const totalPossible = perQuestion.reduce((s, q) => s + q.totalRows, 0);
  const totalSubstantive = perQuestion.reduce((s, q) => s + q.substantive, 0);
  const overallResponseRate = totalPossible ? totalAnswers / totalPossible : 0;
  const allLengths = [];
  perQuestion.forEach(q => {
    // Recover per-answer lengths from buckets approximation isn't needed —
    // we recompute from raw values for accuracy.
    const v = openVars.find(x => x.name === q.name);
    if (!v) return;
    v.values.forEach(val => {
      if (isNonEmpty(val)) allLengths.push(wordCount(val));
    });
  });
  const aggMeanWords = allLengths.length ? allLengths.reduce((s, n) => s + n, 0) / allLengths.length : 0;

  // ---------- Render: aggregate card ----------
  setText('oeKindLabel',       CONFIG.kind_label_long);
  setText('oeAggregateTitle',
    openVars.length === 1
      ? CONFIG.kind_label_long + ': one field, ' + totalAnswers + ' ' + (totalAnswers === 1 ? CONFIG.answer_noun : CONFIG.answer_noun_plural)
      : CONFIG.kind_label_long + ': ' + openVars.length + ' fields, ' + totalAnswers + ' total ' + CONFIG.answer_noun_plural
  );
  setText('oeAggregateSub',
    overallResponseRate >= 0.70 ? 'Most respondents wrote something. Read on for each question.' :
    overallResponseRate >= 0.40 ? 'Mixed response rate. Some questions land, others miss.' :
    overallResponseRate >= 0.10 ? 'Low response rate. Treat themes as tentative.' :
                                  'Very few answers. Themes are not yet supportable.'
  );
  setText('oeStatFields',       String(openVars.length));
  setText('oeStatAnswers',      String(totalAnswers));
  setText('oeStatResponseRate', Math.round(overallResponseRate * 100) + '%');
  setText('oeStatAvgWords',     aggMeanWords.toFixed(1));
  setText('oeStatSubstantive',  String(totalSubstantive));

  // ---------- Render: per-question cards ----------
  const tmpl  = document.getElementById('oeQuestionTemplate');
  const wrap  = document.getElementById('oeQuestions');
  const empty = document.getElementById('oeEmpty');
  if (empty) empty.hidden = true;
  if (!tmpl || !wrap) return;

  perQuestion.forEach((q, idx) => {
    const frag = tmpl.content.cloneNode(true);
    const root = frag.querySelector('.oe-question');
    root.setAttribute('data-question-id', q.name);

    fill(root, 'kindShort', CONFIG.kind_label_short);
    fill(root, 'title',     q.label);
    fill(root, 'meta',
      q.answered + ' of ' + q.totalRows + ' answered  ·  mean ' + q.meanWords.toFixed(1) + ' words');
    fill(root, 'answered',    q.answered);
    fill(root, 'rate',        Math.round(q.responseRate * 100) + '%');
    fill(root, 'meanWords',   q.meanWords.toFixed(1));
    fill(root, 'medianWords', q.medianWords.toFixed(1));
    fill(root, 'minWords',    q.minWords);
    fill(root, 'maxWords',    q.maxWords);

    // Length buckets — compute bar widths against the largest bucket.
    const bMax = Math.max(q.buckets.single, q.buckets.short, q.buckets.medium, q.buckets.long, 1);
    fillBucket(root, 'single', q.buckets.single, bMax, 'bucketSingle');
    fillBucket(root, 'short',  q.buckets.short,  bMax, 'bucketShort');
    fillBucket(root, 'medium', q.buckets.medium, bMax, 'bucketMedium');
    fillBucket(root, 'long',   q.buckets.long,   bMax, 'bucketLong');

    // Top words
    const uniHost = root.querySelector('[data-fill="unigrams"]');
    if (uniHost) {
      uniHost.innerHTML = '';
      if (!q.topUnigrams.length) {
        const empty = document.createElement('span');
        empty.className = 'oe-pill-empty';
        empty.textContent = 'No frequent words.';
        uniHost.appendChild(empty);
      } else {
        const maxU = q.topUnigrams[0].count || 1;
        q.topUnigrams.forEach(u => {
          uniHost.appendChild(pill(u.word, u.count, maxU));
        });
      }
    }
    const biHost = root.querySelector('[data-fill="bigrams"]');
    if (biHost) {
      biHost.innerHTML = '';
      if (!q.topBigrams.length) {
        const emptyEl = document.createElement('span');
        emptyEl.className = 'oe-pill-empty';
        emptyEl.textContent = 'No repeated phrases yet.';
        biHost.appendChild(emptyEl);
      } else {
        const maxB = q.topBigrams[0].count || 1;
        q.topBigrams.forEach(b => {
          biHost.appendChild(pill(b.phrase, b.count, maxB));
        });
      }
    }

    // Sample quotes
    const quotesHost = root.querySelector('[data-fill="quotes"]');
    if (quotesHost) {
      quotesHost.innerHTML = '';
      if (!q.sampleQuotes.length) {
        const li = document.createElement('li');
        li.className = 'oe-quote-empty';
        li.textContent = 'No sample responses available.';
        quotesHost.appendChild(li);
      } else {
        q.sampleQuotes.forEach(qt => {
          const li = document.createElement('li');
          li.className = 'oe-quote';
          // textContent prevents any HTML injection from user-supplied answers.
          const span = document.createElement('span');
          span.className = 'oe-quote-mark';
          span.setAttribute('aria-hidden', 'true');
          span.textContent = '“';
          li.appendChild(span);
          const body = document.createElement('span');
          body.className = 'oe-quote-text';
          body.textContent = qt;
          li.appendChild(body);
          quotesHost.appendChild(li);
        });
      }
    }

    fill(root, 'interp', q.interp);

    wrap.appendChild(frag);
  });

  // ---------- Closing interpretation ----------
  const verdict = (() => {
    if (totalAnswers === 0)                return 'No qualitative signal yet';
    if (overallResponseRate >= 0.70 && aggMeanWords >= 8)  return 'Strong qualitative signal';
    if (overallResponseRate >= 0.40)       return 'Workable qualitative signal';
    if (overallResponseRate >= 0.10)       return 'Thin qualitative signal';
    return 'Sparse qualitative signal';
  })();

  let lead, follow;
  if (totalAnswers === 0) {
    lead = CONFIG.interp_lead_none;
    follow = 'No one answered the open-ended ' + (openVars.length === 1 ? 'question' : 'questions') + '. Open-ended fields work best when placed early and labeled with a clear purpose.';
  } else if (verdict === 'Strong qualitative signal') {
    lead = CONFIG.interp_lead_strong + ' ' + totalAnswers + ' ' + CONFIG.answer_noun_plural + ' across ' + openVars.length + ' ' + (openVars.length === 1 ? CONFIG.field_noun : CONFIG.field_noun_plural) + ', averaging ' + aggMeanWords.toFixed(1) + ' words.';
    follow = 'There is enough volume and detail to move into theme analysis. Look for repeated phrases above as the starting point.';
  } else if (verdict === 'Workable qualitative signal') {
    lead = totalAnswers + ' ' + CONFIG.answer_noun_plural + ' at ' + aggMeanWords.toFixed(1) + ' words on average. Workable, but read it in context with the closed items.';
    follow = 'Themes derived from this volume should be presented as tentative until a larger sample confirms.';
  } else if (verdict === 'Thin qualitative signal') {
    lead = CONFIG.interp_lead_weak + ' Only ' + Math.round(overallResponseRate * 100) + '% of respondents wrote anything.';
    follow = 'Use the answers as illustrative quotes alongside numeric findings rather than as the basis for themes.';
  } else {
    lead = totalAnswers + ' ' + CONFIG.answer_noun_plural + ' across ' + openVars.length + ' ' + (openVars.length === 1 ? CONFIG.field_noun : CONFIG.field_noun_plural) + '.';
    follow = 'Volume is too low to support theme claims. Surface as quotes if at all.';
  }
  setInterp(lead, follow);

  // ---------- Refresh button ----------
  const refreshBtn = document.getElementById('oeRefreshBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      // Easiest correct path: re-run the page.
      window.location.reload();
    });
  }

  // ---------- Expose state for Save-to-report ----------
  exposeAppState({
    perQuestion: perQuestion,
    aggregate:   {
      fields:        openVars.length,
      answers:       totalAnswers,
      responseRate:  overallResponseRate,
      avgWords:      aggMeanWords,
      substantive:   totalSubstantive,
    },
    // Signals consumed by the Strength Index 'Open-Ended Alignment' domain
    // (matches the inputs that computeOpenEnded() in strength-index.js uses).
    signals: {
      response_rate:      overallResponseRate,
      mean_words:         aggMeanWords,
      substantive_count:  totalSubstantive,
    },
    verdict: verdict,
  });

  // ====================================================================
  // Helpers
  // ====================================================================
  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value);
  }
  function fill(root, key, value) {
    const el = root.querySelector('[data-fill="' + key + '"]');
    if (el) el.textContent = String(value);
  }
  function fillBucket(root, key, count, max, labelKey) {
    const bucket = root.querySelector('.oe-bucket[data-bucket="' + key + '"]');
    if (!bucket) return;
    const bar = bucket.querySelector('.oe-bucket-bar > span');
    if (bar) {
      const pct = max ? Math.round((count / max) * 100) : 0;
      // Defer to ensure the transition runs.
      setTimeout(() => { bar.style.width = pct + '%'; }, 60);
    }
    const lbl = bucket.querySelector('[data-fill="' + labelKey + '"]');
    if (lbl) lbl.textContent = String(count);
  }
  function pill(word, count, max) {
    const span = document.createElement('span');
    span.className = 'oe-pill';
    const intensity = Math.max(0.20, Math.min(1, count / Math.max(max, 1)));
    span.style.setProperty('--oe-pill-intensity', intensity.toFixed(2));
    const w = document.createElement('span');
    w.className = 'oe-pill-word';
    w.textContent = word;
    const c = document.createElement('span');
    c.className = 'oe-pill-count';
    c.textContent = String(count);
    span.appendChild(w);
    span.appendChild(c);
    return span;
  }
  function setAggregateText(title, sub) {
    setText('oeAggregateTitle', title);
    setText('oeAggregateSub', sub);
  }
  function setInterp(lead, follow) {
    const elLead   = document.getElementById('oeInterpLead');
    const elFollow = document.getElementById('oeInterpFollow');
    if (elLead)   elLead.textContent   = lead;
    if (elFollow) elFollow.textContent = follow;
  }
  function showEmpty(message) {
    const empty = document.getElementById('oeEmpty');
    if (empty) {
      empty.hidden = false;
      if (message) {
        const p = empty.querySelector('p');
        if (p) p.textContent = message;
      }
    }
  }
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'open_ended_summary',
      app_name: 'Open-Ended Summary',
      summary:  buildSummary(payload),
      dataset:  {
        source:     dataset.source || '',
        rowCount:   rowCount,
        fromUpload: window.OPEN_ENDED_DATASET_SOURCE === 'uploaded',
      },
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function buildSummary(payload) {
    const a = payload.aggregate || {};
    if (!a.fields)  return 'No open-ended fields in this dataset.';
    if (!a.answers) return a.fields + ' open-ended field(s), no responses yet.';
    return a.answers + ' ' + (a.answers === 1 ? CONFIG.answer_noun : CONFIG.answer_noun_plural) +
      ' across ' + a.fields + ' ' + (a.fields === 1 ? CONFIG.field_noun : CONFIG.field_noun_plural) +
      '  ·  ' + Math.round((a.responseRate || 0) * 100) + '% response rate, avg ' +
      (a.avgWords || 0).toFixed(1) + ' words.';
  }
})();
