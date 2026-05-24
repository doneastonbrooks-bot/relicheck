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
from .suggest import suggest_test


def _handle(req: dict) -> dict:
    op = req.get("op")
    args = req.get("args") or {}
    req_id = req.get("id")

    try:
        if op == "ping":
            result: Any = {"pong": True}
        elif op == "analysis.suggest":
            # args: {"pairs": [{"predictor_id":..,"predictor_type":..,"predictor_groups":..,
            #                   "outcome_id":..,"outcome_type":..,"outcome_groups":..,
            #                   "predictor_name":..,"outcome_name":..}, ...]}
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
            # args: {"test": "...", "a": [...], "b": [...]}
            # Inputs are passed inline — Phase 2 does not yet talk to a local DB.
            test = args.get("test")
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
