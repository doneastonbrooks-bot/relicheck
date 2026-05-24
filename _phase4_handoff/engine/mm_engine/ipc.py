"""stdin/stdout JSON loop for the Python sidecar.

Reads one JSON object per line from stdin, dispatches to the appropriate
mm_engine function, writes one JSON object per line to stdout. The Tauri
side spawns this module with `python -u -m mm_engine.ipc` and talks to
it line by line.

Wire protocol:

    request:  {"id": <int>, "op": "analysis.suggest" | "analysis.run", "args": {...}}
    response: {"id": <int>, "ok": true,  "result": {...}}
              {"id": <int>, "ok": false, "error": "<reason>"}

The `id` field round-trips so the Tauri side can match responses to
in-flight requests if it ever pipelines.
"""

from __future__ import annotations

import json
import sys
import traceback
from typing import Any

from . import tests as _tests
from . import ingest as _ingest
from . import dataset as _dataset
from .suggest import suggest_test


# ---------------------------------------------------------------------------
# Helpers for the cached-dataset analysis flow (Phase 4)
# ---------------------------------------------------------------------------

def _column_role_meta(df, name):
    """Return (dtype_label, group_count) for a column the way
    stats_suggest_test expects them. dtype_label maps to the
    NUMERIC_TYPES / CAT_TYPES sets in suggest.py."""
    import pandas as _pd
    if name not in df.columns:
        return ("unknown", 0)
    s = df[name].dropna()
    if s.empty:
        return ("unknown", 0)
    # Numeric (any numeric dtype, or object dtype that fully coerces)
    if _pd.api.types.is_numeric_dtype(s):
        return ("numeric", int(s.nunique()))
    coerced = _pd.to_numeric(s, errors="coerce")
    if coerced.notna().all():
        return ("numeric", int(s.nunique()))
    # Boolean -> binary
    if _pd.api.types.is_bool_dtype(s) or (s.nunique() == 2):
        return ("binary", int(s.nunique()))
    # Anything else with bounded distinct values is a category
    return ("category", int(s.nunique()))


def _handle(req: dict) -> dict:
    op = req.get("op")
    args = req.get("args") or {}
    req_id = req.get("id")

    try:
        if op == "ping":
            result: Any = {"pong": True}
        elif op == "analysis.suggest":
            # Two calling conventions:
            #
            # (A) Phase-4 cached-dataset form:
            #     {"ingest_id": "...", "predictor_names": [...], "outcome_names": [...]}
            #     Engine reads dtypes + group counts from the cached DataFrame.
            #
            # (B) Legacy pairs form (Phase 2 batch 1 smoke test):
            #     {"pairs": [{predictor_type, outcome_type, predictor_groups, outcome_groups, ...}, ...]}
            #     Webview sends everything inline. Still supported.
            if args.get("ingest_id"):
                iid = str(args.get("ingest_id"))
                entry = _dataset.get_dataset(iid)
                if entry is None:
                    return {"id": req_id, "ok": False, "error": f"dataset not found in cache: {iid}"}
                df = entry["df"]
                preds = args.get("predictor_names") or []
                outs = args.get("outcome_names") or []
                suggestions = []
                skipped = []
                for pname in preds:
                    p_type, p_groups = _column_role_meta(df, pname)
                    for oname in outs:
                        if pname == oname:
                            continue  # don't pair a column with itself
                        o_type, o_groups = _column_role_meta(df, oname)
                        test = suggest_test(p_type, o_type, p_groups, o_groups)
                        row = {
                            "predictor_name": pname,
                            "predictor_type": p_type,
                            "predictor_distinct": p_groups,
                            "outcome_name": oname,
                            "outcome_type": o_type,
                            "outcome_distinct": o_groups,
                            "test": test,
                        }
                        if test is None:
                            row["skip_reason"] = f"no classical test maps to {p_type} predictor x {o_type} outcome"
                            skipped.append(row)
                        else:
                            suggestions.append(row)
                result = {"suggestions": suggestions, "skipped": skipped}
            else:
                pairs = args.get("pairs") or []
                suggestions = []
                skipped = []
                for p in pairs:
                    test = suggest_test(
                        str(p.get("predictor_type", "")),
                        str(p.get("outcome_type", "")),
                        int(p.get("predictor_groups", 0)),
                        int(p.get("outcome_groups", 0)),
                    )
                    row = {
                        "predictor_id": p.get("predictor_id"),
                        "predictor_name": p.get("predictor_name"),
                        "predictor_type": p.get("predictor_type"),
                        "outcome_id": p.get("outcome_id"),
                        "outcome_name": p.get("outcome_name"),
                        "outcome_type": p.get("outcome_type"),
                        "test": test,
                    }
                    if test is None:
                        row["skip_reason"] = "no classical test maps to this type pair"
                        skipped.append(row)
                    else:
                        suggestions.append(row)
                result = {"suggestions": suggestions, "skipped": skipped}
        elif op == "analysis.run":
            # Two calling conventions:
            #
            # (A) Phase-4 cached-dataset form:
            #     {"ingest_id": "...", "test": "...",
            #      "predictor_name": "...", "outcome_name": "..."}
            #     Engine reads both columns out of the cache.
            #
            # (B) Phase-2 inline form (kept for the sidecar smoke test):
            #     {"test": "...", "a": [...], "b": [...]}
            test = args.get("test")
            if args.get("ingest_id"):
                iid = str(args.get("ingest_id"))
                pname = str(args.get("predictor_name", ""))
                oname = str(args.get("outcome_name", ""))
                a = _dataset.get_column(iid, pname)
                b = _dataset.get_column(iid, oname)
                if a is None:
                    return {"id": req_id, "ok": False, "error": f"column not found: {pname!r}"}
                if b is None:
                    return {"id": req_id, "ok": False, "error": f"column not found: {oname!r}"}
            else:
                a = args.get("a") or []
                b = args.get("b") or []
            if test == "chi_square":
                result = _tests.chi_square(a, b)
            elif test == "t_test":
                result = _tests.t_test(a, b)
            elif test == "anova":
                result = _tests.anova(a, b)
            elif test == "pearson":
                result = _tests.pearson(a, b)
            else:
                return {"id": req_id, "ok": False, "error": f"unknown test: {test!r}"}
            # tests.* may return {"ok": False, "error": ...} for bad input — pass it through
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "unknown error")}
        elif op == "ingest.sniff":
            # args: {"path": "/abs/path/to/file"}
            result = _ingest.sniff(str(args.get("path", "")))
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        elif op == "ingest.csv":
            sep = args.get("sep")
            result = _ingest.read_csv(str(args.get("path", "")), sep=sep)
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        elif op == "ingest.excel":
            sheet = args.get("sheet")
            result = _ingest.read_excel(str(args.get("path", "")), sheet=sheet)
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        elif op == "ingest.spss":
            result = _ingest.read_spss(str(args.get("path", "")))
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        elif op == "ingest.stata":
            result = _ingest.read_stata(str(args.get("path", "")))
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        elif op == "ingest.json":
            result = _ingest.read_json(str(args.get("path", "")))
            if isinstance(result, dict) and result.get("ok") is False:
                return {"id": req_id, "ok": False, "error": result.get("error", "ingest failed")}
        else:
            return {"id": req_id, "ok": False, "error": f"unknown op: {op!r}"}

        return {"id": req_id, "ok": True, "result": result}
    except Exception as e:
        return {
            "id": req_id,
            "ok": False,
            "error": f"{type(e).__name__}: {e}",
            "trace": traceback.format_exc(),
        }


def main() -> None:
    # Greet so the Tauri side knows the sidecar is alive
    print(json.dumps({"event": "ready", "version": "0.1.0"}), flush=True)
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            req = json.loads(line)
        except json.JSONDecodeError as e:
            print(json.dumps({"id": None, "ok": False, "error": f"invalid json: {e}"}), flush=True)
            continue
        resp = _handle(req)
        print(json.dumps(resp), flush=True)


if __name__ == "__main__":
    main()
