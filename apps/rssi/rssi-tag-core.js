/* =============================================================================
   rssi-tag-core.js — Pure functions for the standalone RSSI tagging surface.

   No DOM, no localStorage, no fetch. Imported by:
     - apps/rssi/rssi-upload.js (browser, IIFE wrap)
     - apps/__harness/rssi_tag_emit.test.js (Node, vm sandbox)

   Exposes window.RSSI_TAG_CORE in the browser and a CommonJS module.exports
   in Node. The two surfaces share the same exact functions; nothing
   browser-specific lives here.

   v1 scope (M1 baseline; expanded over M2–M7):
     - inferColumnRoles(parsed)      → per-column auto-detect proposals
     - materializeDataset(parsed, columnRoles, config, sourceLabel)
                                      → engine-shape dataset
     - validateTags(columnRoles, config)
                                      → { blockers, hints } for the tag stage UI
     - dedupeHeaders(headers)        → suffix duplicates as name__2, name__3
     - applyParseNormalizations()    → stub (M4 will flesh out BOM strip,
                                            sentinel-null, locale-decimal)
     - fileContentHash()             → stub (M5 will hash per the
                                            full/sampled rule documented in the plan)

   Role vocabulary (seven roles, forward-compatible with platform #5/#6):
     likert | numeric | demographic | criterion | identifier | free_text | ignore

   Engine emit contract (per role):
     likert      → { types: ['likert'],      values: numeric-or-null,
                     [construct], [reverse_coded], [anchor_count] }
     numeric     → { types: ['numeric'],     values: numeric-or-null }
     demographic → { types: ['categorical'], values: string }
                   AND name appears in config.demographic_columns
     criterion   → { types: ['numeric'],     values: numeric-or-null }
                   AND name appears as config.criterion_column
     identifier  → not emitted (engine ignores)
     free_text   → { types: ['open'],        values: string }
     ignore      → not emitted
   ============================================================================= */
(function (root, factory) {
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = factory();
  } else {
    root.RSSI_TAG_CORE = factory();
  }
}(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this), function () {
  'use strict';

  const ROLES = Object.freeze([
    'likert', 'numeric', 'demographic', 'criterion',
    'identifier', 'free_text', 'ignore',
  ]);

  /* ────────────────────────────────────────────────────────────
   * Header dedup. "score, score, score" → "score, score__2, score__3".
   * Stable: the FIRST occurrence keeps the original name.
   * ──────────────────────────────────────────────────────────── */
  function dedupeHeaders(headers) {
    const seen = {};
    return headers.map(function (h) {
      const base = String(h);
      const n = (seen[base] = (seen[base] || 0) + 1);
      return n === 1 ? base : base + '__' + n;
    });
  }

  /* ────────────────────────────────────────────────────────────
   * Auto-detect proposals from a parsed file.
   *
   * Conservative rules (Phase 1 Q2 — no demographic name-heuristics in v1):
   *   - All-numeric, integer min/max, range 1..10, ≤11 uniques → 'likert' +
   *     anchorCount = (max - min + 1)
   *   - All-numeric otherwise                                  → 'numeric'
   *   - Text, small unique count (≤8 or ≤20% of non-empty)     → 'demographic'
   *                  (proposed, NOT engine-activated — config.demographic_columns
   *                   is only populated when the user confirms via the tag stage)
   *   - Text otherwise                                         → 'free_text'
   *
   * Identifier auto-detect is deferred to M7. v1.0 surfaces 'identifier' in
   * the dropdown but does not pre-suggest it.
   * ──────────────────────────────────────────────────────────── */
  function inferColumnRoles(parsed) {
    return parsed.headers.map(function (h) {
      const rawValues = parsed.rows.map(function (r) { return r[h]; });
      const nonEmpty = rawValues.filter(function (v) { return v !== '' && v != null; });

      let autoRole = 'free_text';
      let autoAnchorCount = null;

      if (nonEmpty.length > 0) {
        const numeric = nonEmpty.filter(function (v) {
          return !isNaN(parseFloat(v)) && isFinite(parseFloat(v));
        });
        const allNumeric = numeric.length === nonEmpty.length;
        if (allNumeric) {
          const nums = numeric.map(parseFloat);
          const min = Math.min.apply(null, nums);
          const max = Math.max.apply(null, nums);
          const uniques = (new Set(nums)).size;
          if (Number.isInteger(min) && Number.isInteger(max)
              && (max - min) >= 1 && (max - min) <= 10
              && uniques <= 11) {
            autoRole = 'likert';
            autoAnchorCount = (max - min) + 1;
          } else {
            autoRole = 'numeric';
          }
        } else {
          const uniqText = Array.from(new Set(nonEmpty.map(String)));
          if (uniqText.length <= Math.max(8, Math.floor(nonEmpty.length * 0.2))) {
            autoRole = 'demographic';
          } else {
            autoRole = 'free_text';
          }
        }
      }

      return {
        name: h,
        autoRole: autoRole,
        autoAnchorCount: autoAnchorCount,
        sample: rawValues.slice(0, 4),
      };
    });
  }

  /* ────────────────────────────────────────────────────────────
   * Materialize the engine-shape dataset from confirmed tags.
   *
   *   parsed       — { headers, rows } from parseDelimited/parseXlsx
   *   columnRoles  — [{ name, role, construct?, reverseCoded?, anchorCount? }, …]
   *   config       — { reverse_coded_confirmed?: bool } (and future flags)
   *   sourceLabel  — string, surfaces as dataset.source
   *
   * Emits { source, rowCount, variables, config } per the engine's
   * computeLensesFromDataset contract.
   *
   * Precedence note (Refinement 1, locked in Phase 1):
   *   columnRoles is treated as the user-final tagging. Auto-detect output
   *   is the proposal; whoever calls materialize passes the result of
   *   user-touched-wins resolution.
   * ──────────────────────────────────────────────────────────── */
  function materializeDataset(parsed, columnRoles, config, sourceLabel) {
    config = config || {};
    const rolesByName = {};
    columnRoles.forEach(function (cr) { rolesByName[cr.name] = cr; });

    const variables = [];

    columnRoles.forEach(function (cr) {
      const role = cr.role;
      if (role === 'identifier' || role === 'ignore') return;

      const h = cr.name;
      const rawValues = parsed.rows.map(function (r) { return r[h]; });

      if (role === 'likert' || role === 'numeric' || role === 'criterion') {
        const values = rawValues.map(function (v) {
          if (v === '' || v == null) return null;
          const n = parseFloat(v);
          return isFinite(n) ? n : null;
        });
        const v = {
          name:   h,
          types:  [role === 'likert' ? 'likert' : 'numeric'],
          label:  h,
          values: values,
        };
        if (role === 'likert') {
          if (typeof cr.construct === 'string' && cr.construct.trim() !== '') {
            v.construct = cr.construct.trim();
          }
          if (Object.prototype.hasOwnProperty.call(cr, 'reverseCoded')) {
            v.reverse_coded = !!cr.reverseCoded;
          }
          if (cr.anchorCount != null) {
            const a = Number(cr.anchorCount);
            if (Number.isFinite(a) && a >= 2 && a <= 11) v.anchor_count = a;
          }
        }
        variables.push(v);
        return;
      }

      if (role === 'demographic') {
        variables.push({
          name:   h,
          types:  ['categorical'],
          label:  h,
          values: rawValues.map(function (v) { return v == null ? '' : String(v); }),
        });
        return;
      }

      // free_text (default for anything else passed through)
      variables.push({
        name:   h,
        types:  ['open'],
        label:  h,
        values: rawValues.map(function (v) { return v == null ? '' : String(v); }),
      });
    });

    /* ── dataset.config ───────────────────────────────────────── */
    const outConfig = {};
    if (Object.prototype.hasOwnProperty.call(config, 'reverse_coded_confirmed')) {
      outConfig.reverse_coded_confirmed = !!config.reverse_coded_confirmed;
    }
    const demoNames = columnRoles
      .filter(function (cr) { return cr.role === 'demographic' && cr.userConfirmed === true; })
      .map(function (cr) { return cr.name; });
    if (demoNames.length) outConfig.demographic_columns = demoNames;
    const critNames = columnRoles
      .filter(function (cr) { return cr.role === 'criterion'; })
      .map(function (cr) { return cr.name; });
    if (critNames.length === 1) outConfig.criterion_column = critNames[0];

    const dataset = {
      source:   sourceLabel || '',
      rowCount: parsed.rows.length,
      variables: variables,
    };
    if (Object.keys(outConfig).length) dataset.config = outConfig;
    return dataset;
  }

  /* ────────────────────────────────────────────────────────────
   * Soft validation for the tag stage UI (Refinement 2).
   *
   *   Returns { blockers, hints }.
   *
   * Blockers prevent the "Score my data" transition:
   *   - no_likert_tagged: at least one column must carry role='likert'.
   *
   * Hints render inline next to the relevant row(s); never block:
   *   - construct_too_small  — a construct has < 3 Likert items
   *     (§4B sub-1 needs ≥ 3 items per scale).
   *   - multiple_criterion   — > 1 column tagged criterion (engine reads
   *     one criterion_column; extras silently ignored).
   *   - unusual_likert_range — anchorCount > 8 (most validated scales
   *     are 3/4/5/6/7; > 8 often signals a parsing miscategorization).
   *
   * Future hints (M4): suspiciously-high missingness, positive item-total
   * correlation on a reverse-flagged item.
   * ──────────────────────────────────────────────────────────── */
  function validateTags(columnRoles, _config) {
    const blockers = [];
    const hints    = [];

    const likertRows = columnRoles.filter(function (cr) { return cr.role === 'likert'; });
    if (likertRows.length === 0) {
      blockers.push({ kind: 'no_likert_tagged' });
    }

    const byConstruct = {};
    likertRows.forEach(function (cr) {
      const c = (typeof cr.construct === 'string') ? cr.construct.trim() : '';
      if (c === '') return;
      byConstruct[c] = (byConstruct[c] || 0) + 1;
    });
    Object.keys(byConstruct).forEach(function (c) {
      if (byConstruct[c] < 3) {
        hints.push({ kind: 'construct_too_small', construct: c, count: byConstruct[c] });
      }
    });

    const criterionRows = columnRoles.filter(function (cr) { return cr.role === 'criterion'; });
    if (criterionRows.length > 1) {
      hints.push({ kind: 'multiple_criterion', count: criterionRows.length,
                   columns: criterionRows.map(function (cr) { return cr.name; }) });
    }

    likertRows.forEach(function (cr) {
      if (cr.anchorCount != null) {
        const a = Number(cr.anchorCount);
        if (Number.isFinite(a) && a > 8) {
          hints.push({ kind: 'unusual_likert_range', column: cr.name, anchorCount: a });
        }
      }
    });

    return { blockers: blockers, hints: hints };
  }

  /* ────────────────────────────────────────────────────────────
   * Parse-normalization stubs (M4 will flesh out).
   * Kept here so the module's surface is stable from M1 onward.
   * ──────────────────────────────────────────────────────────── */
  function applyParseNormalizations(text /* , opts */) {
    // M4 will implement: BOM strip, sentinel-null mapping (N/A, NA, null, -, --, #N/A),
    // locale-decimal auto-detect + manual toggle (file-wide), Excel-error scrub.
    // Identity for now so the M1 refactor preserves current parse behavior.
    return text;
  }

  function fileContentHash(arrayBuffer) {
    // M2 minimal: FNV-1a 32-bit over the byte stream. Stable across reloads
    // of the same file; collision probability is fine for localStorage
    // tag-cache keying (the cost of a rare collision is a stale auto-fill
    // the user will correct in seconds — not a data-loss event).
    //
    // M5 will replace with the spec'd SHA-256 + sampled-large-file rule.
    // The "fnv1a-" prefix means M5's "sha256-…" keys won't collide with M2
    // keys — old M2 caches simply miss after the upgrade, which is correct:
    // we don't want a weaker hash to authoritatively name a cache once a
    // stronger one ships.
    if (arrayBuffer == null) return null;
    let bytes;
    if (arrayBuffer instanceof Uint8Array) {
      bytes = arrayBuffer;
    } else if (typeof ArrayBuffer !== 'undefined' && arrayBuffer instanceof ArrayBuffer) {
      bytes = new Uint8Array(arrayBuffer);
    } else if (typeof arrayBuffer === 'string') {
      // Node-side / harness convenience: hash a string by its UTF-8 bytes.
      const s = arrayBuffer;
      const arr = new Uint8Array(s.length);
      for (let i = 0; i < s.length; i++) arr[i] = s.charCodeAt(i) & 0xff;
      bytes = arr;
    } else {
      return null;
    }
    let h = 0x811c9dc5;
    for (let i = 0; i < bytes.length; i++) {
      h ^= bytes[i];
      // h * 16777619, mod 2^32
      h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
    }
    return 'fnv1a-' + h.toString(16).padStart(8, '0');
  }

  return {
    ROLES: ROLES,
    inferColumnRoles: inferColumnRoles,
    materializeDataset: materializeDataset,
    validateTags: validateTags,
    dedupeHeaders: dedupeHeaders,
    applyParseNormalizations: applyParseNormalizations,
    fileContentHash: fileContentHash,
  };
}));
