/* contextual-lens.js — Contextual Lens shared component for ReliCheck Studios.
 *
 * window.ContextualLens = {
 *   panel(type, record, idPrefix) → HTML string
 *   gather(type, idPrefix) → { field: value|null, ... }
 *   populate(type, record, idPrefix) — fills DOM fields from a record
 * }
 *
 * type: 'project' | 'code' | 'theme'
 *
 * Contextual Lens helps users interpret patterns in relation to the people,
 * settings, conditions, and consequences that give those patterns meaning.
 *
 * Six dimensions — Context, Voice, Position, Representation,
 * Counter-Pattern, Consequence — apply at code, theme, and project level.
 * Culture is part of context; so are role, setting, language, access,
 * institutional conditions, history, relationships, power, timing,
 * geography, and consequences.
 */
(function () {
  'use strict';

  function _esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var FIELDS = {
    // Project-level setup fields — prime the analysis before coding begins.
    // Mapped to the four most consequential dimensions at project scope.
    project: [
      {
        id: 'cl_analysis_purpose',
        label: 'Purpose and audience',
        q: 'Why is this analysis being conducted, and who will use the findings?',
      },
      {
        id: 'cl_population_context',
        label: 'Voice — whose voices are in this data?',
        q: 'Who are the respondents? Whose voices are present, and who might be absent or underrepresented?',
      },
      {
        id: 'cl_analyst_positionality',
        label: 'Position — your role in this analysis',
        q: 'What role, identity, or relationship do you bring to this analysis, and how might that shape interpretation?',
      },
      {
        id: 'cl_potential_misuse',
        label: 'Consequence — how findings could be misused',
        q: 'What could happen if findings are used shallowly, incorrectly, or without context?',
      },
    ],
    // Code-level fields — three dimensions most relevant at the code definition stage.
    code: [
      {
        id: 'cl_context',
        label: 'Context',
        q: 'What setting, history, role, condition, or environment shapes responses that receive this code?',
      },
      {
        id: 'cl_structural_framing',
        label: 'Position',
        q: 'What roles, identities, relationships, or power dynamics may shape who gives this kind of response?',
      },
      {
        id: 'cl_misinterpretation_risk',
        label: 'Representation',
        q: 'Could this code be applied or interpreted differently across groups, cases, or contexts?',
      },
    ],
    // Theme-level fields — all six dimensions, in the canonical CL order.
    theme: [
      {
        id: 'cl_context',
        label: 'Context',
        q: 'What setting, history, role, condition, or environment shapes this theme?',
      },
      {
        id: 'cl_group_variation',
        label: 'Voice',
        q: 'Whose words are visible, missing, centered, softened, or overrepresented in this theme?',
      },
      {
        id: 'cl_structural_framing',
        label: 'Position',
        q: 'What roles, identities, relationships, or power dynamics may shape the meaning of this theme?',
      },
      {
        id: 'cl_pattern_type',
        label: 'Representation',
        q: 'Are patterns being interpreted fairly across groups, cases, or contexts?',
      },
      {
        id: 'cl_counter_story',
        label: 'Counter-Pattern',
        q: 'What responses challenge, complicate, or contradict this theme?',
      },
      {
        id: 'cl_action_caution',
        label: 'Consequence',
        q: 'What could happen if this theme is used shallowly, incorrectly, or without context?',
      },
    ],
  };

  function panel(type, record, idPrefix) {
    var fields = FIELDS[type] || [];
    var r = record || {};
    var hasValues = fields.some(function (f) { return r[f.id]; });
    var fieldsHtml = fields.map(function (f) {
      return '<div style="margin-bottom:14px">'
        + '<label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;color:var(--ink-2)">'
        + _esc(f.label)
        + '<span style="display:block;font-weight:400;color:var(--ink-3);margin-top:1px">'
        + _esc(f.q) + '</span></label>'
        + '<textarea id="' + _esc(idPrefix + f.id) + '" '
        + 'style="width:100%;min-height:68px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical;box-sizing:border-box" '
        + 'placeholder="Optional">'
        + _esc(r[f.id] || '') + '</textarea></div>';
    }).join('');

    return '<details class="cl-details" style="border:1px solid #e0d4f5;border-radius:10px;margin-top:16px"'
      + (hasValues ? ' open' : '') + '>'
      + '<summary style="padding:11px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;'
      + 'list-style:none;font-weight:700;font-size:13px;color:var(--ink-2);user-select:none;-webkit-user-select:none">'
      + '<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;'
      + 'border-radius:50%;background:#6B3FA0;color:#fff;font-size:10px;font-weight:800;flex-shrink:0;letter-spacing:0">CL</span>'
      + 'Contextual Lens'
      + '<span style="font-weight:400;color:var(--ink-3);font-size:12px;margin-left:2px">optional</span>'
      + '</summary>'
      + '<div style="padding:14px 16px 16px;border-top:1px solid #e0d4f5">'
      + '<p style="font-size:12.5px;color:var(--ink-3);margin:0 0 16px;line-height:1.55">'
      + 'Contextual Lens helps you interpret patterns in relation to the people, settings, conditions, '
      + 'and consequences that give those patterns meaning. All fields are optional.</p>'
      + fieldsHtml
      + '</div></details>';
  }

  function gather(type, idPrefix) {
    var fields = FIELDS[type] || [];
    var result = {};
    fields.forEach(function (f) {
      var el = document.getElementById(idPrefix + f.id);
      result[f.id] = el ? (el.value.trim() || null) : null;
    });
    return result;
  }

  function populate(type, record, idPrefix) {
    var fields = FIELDS[type] || [];
    var r = record || {};
    fields.forEach(function (f) {
      var el = document.getElementById(idPrefix + f.id);
      if (el) el.value = r[f.id] || '';
    });
  }

  window.ContextualLens = { panel: panel, gather: gather, populate: populate };
})();
