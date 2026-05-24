"""File ingest for MM Studio (Phase 3).

Reads tabular data from CSV, TSV, Excel, SPSS .sav, Stata .dta, and JSON
into a normalized "dataset" dict the rest of MM Studio can use. The dict
mirrors the shape mm_engine.tests expects when paired with the existing
analysis ops, so the analysis tab can read a column out of a committed
dataset without translation.

Wire contract (returned by every public function):

    {
        "ok": True,
        "format": "csv" | "tsv" | "xlsx" | "sav" | "dta" | "json",
        "path": "/abs/path/to/source",
        "columns": [
            {
                "name": "...",
                "dtype": "numeric" | "category" | "text" | "datetime" | "boolean",
                "n_missing": int,
                "n_unique": int,             # only when dtype != text (text is unbounded)
                "label": str | None,         # var label, populated for SAV/DTA
                "value_labels": dict | None, # value -> label, populated for SAV
            },
            ...
        ],
        "rows_preview": [ {col: value, ...}, ... up to 50 rows ],
        "n_rows_total": int,
        "n_cols": int,
        "sheets": ["Sheet1", ...] | None,   # only set for Excel multi-sheet files
        "warnings": [str, ...],
    }

On failure, every function returns {"ok": False, "error": "<reason>"}.
The error string is meant to be user-facing; do not include traceback
text.

Type inference is intentionally light:
- numeric  -> all non-null values parse as float
- category -> non-numeric, fewer than 20 distinct values, or dtype is
              pandas category, or sav had value_labels
- boolean  -> exactly 2 distinct values and one is "true"/"false"/0/1
- datetime -> pandas detected datetime
- text     -> everything else
"""

from __future__ import annotations

import json as _json
import math
import os
from pathlib import Path
from typing import Any

import pandas as pd


# How many rows to ship to the webview for preview. The full dataset
# stays on the engine side until/unless the user commits it.
PREVIEW_ROW_CAP = 50

# Above this many distinct values, a column will not be treated as a
# category candidate. Matches the Studio UI's default.
CATEGORY_THRESHOLD = 20


# ---------------------------------------------------------------------------
# Public dispatcher
# ---------------------------------------------------------------------------


def sniff(path: str) -> dict:
    """Pick the right reader based on file extension."""
    p = _check_path(path)
    if isinstance(p, dict):  # error dict
        return p
    ext = p.suffix.lower()
    if ext == ".csv":
        return read_csv(str(p))
    if ext == ".tsv":
        return read_csv(str(p), sep="\t")
    if ext in (".xlsx", ".xls", ".xlsm"):
        return read_excel(str(p))
    if ext == ".sav":
        return read_spss(str(p))
    if ext == ".dta":
        return read_stata(str(p))
    if ext == ".json":
        return read_json(str(p))
    return {
        "ok": False,
        "error": f"Unsupported file type: {ext or '(no extension)'}. "
        f"Supported: .csv, .tsv, .xlsx, .xls, .sav, .dta, .json.",
    }


# ---------------------------------------------------------------------------
# CSV / TSV
# ---------------------------------------------------------------------------


def read_csv(path: str, sep: str | None = None) -> dict:
    """Read a CSV or TSV. If sep is None, pandas sniffs the delimiter."""
    p = _check_path(path)
    if isinstance(p, dict):
        return p
    try:
        # engine="python" allows sep=None auto-detection. Fall back to
        # the C engine with a guessed sep if the auto-detect fails.
        if sep is None:
            df = pd.read_csv(p, sep=None, engine="python")
        else:
            df = pd.read_csv(p, sep=sep)
    except Exception as e:
        return {"ok": False, "error": f"Could not read CSV: {_userize(e)}"}

    fmt = "tsv" if (sep == "\t" or p.suffix.lower() == ".tsv") else "csv"
    return _finalize(df, str(p), fmt)


# ---------------------------------------------------------------------------
# Excel
# ---------------------------------------------------------------------------


def read_excel(path: str, sheet: str | int | None = None) -> dict:
    """Read an Excel workbook. If sheet is None and there are multiple
    sheets, returns ok with the sheet list and no rows so the UI can ask
    the user which sheet to load."""
    p = _check_path(path)
    if isinstance(p, dict):
        return p
    try:
        xls = pd.ExcelFile(p)
    except Exception as e:
        return {"ok": False, "error": f"Could not open Excel file: {_userize(e)}"}

    sheets = list(xls.sheet_names)
    if sheet is None and len(sheets) > 1:
        # Multi-sheet workbook, no sheet specified: return the sheet list
        # so the UI can prompt for selection. No rows yet.
        return {
            "ok": True,
            "format": "xlsx",
            "path": str(p),
            "columns": [],
            "rows_preview": [],
            "n_rows_total": 0,
            "n_cols": 0,
            "sheets": sheets,
            "needs_sheet_pick": True,
            "warnings": [],
        }

    target = sheet if sheet is not None else sheets[0]
    try:
        df = pd.read_excel(xls, sheet_name=target)
    except Exception as e:
        return {"ok": False, "error": f"Could not read Excel sheet {target!r}: {_userize(e)}"}

    result = _finalize(df, str(p), "xlsx")
    if result.get("ok"):
        result["sheets"] = sheets
        result["sheet"] = target if isinstance(target, str) else sheets[target]
    return result


# ---------------------------------------------------------------------------
# SPSS .sav
# ---------------------------------------------------------------------------


def read_spss(path: str) -> dict:
    """Read an SPSS .sav file. Preserves variable labels and value
    labels via pyreadstat."""
    p = _check_path(path)
    if isinstance(p, dict):
        return p
    try:
        import pyreadstat  # type: ignore
    except ImportError:
        return {
            "ok": False,
            "error": "SPSS support is not installed in this build. Reinstall MM Studio.",
        }
    try:
        df, meta = pyreadstat.read_sav(str(p))
    except Exception as e:
        return {"ok": False, "error": f"Could not read SPSS file: {_userize(e)}"}

    result = _finalize(df, str(p), "sav")
    if not result.get("ok"):
        return result

    # Decorate columns with variable labels + value labels
    var_labels = getattr(meta, "column_names_to_labels", {}) or {}
    val_labels = getattr(meta, "variable_value_labels", {}) or {}
    for col in result["columns"]:
        name = col["name"]
        if name in var_labels and var_labels[name]:
            col["label"] = var_labels[name]
        if name in val_labels:
            # Keys can come back as floats from pyreadstat; stringify for
            # JSON friendliness while keeping the mapping intact.
            col["value_labels"] = {str(k): v for k, v in val_labels[name].items()}
            # If we have value labels, treat as category
            col["dtype"] = "category"
    return result


# ---------------------------------------------------------------------------
# Stata .dta
# ---------------------------------------------------------------------------


def read_stata(path: str) -> dict:
    """Read a Stata .dta file via pandas. Preserves variable labels."""
    p = _check_path(path)
    if isinstance(p, dict):
        return p
    try:
        # Pandas 2.x StataReader takes only the path; iterator kwarg was
        # removed. Read the frame and labels via the same reader object
        # so we get both without double-parsing.
        with pd.io.stata.StataReader(p) as reader:
            df = reader.read()
            try:
                var_labels = reader.variable_labels()
            except Exception:
                var_labels = {}
            try:
                value_labels = reader.value_labels()
            except Exception:
                value_labels = {}
    except Exception as e:
        return {"ok": False, "error": f"Could not read Stata file: {_userize(e)}"}

    result = _finalize(df, str(p), "dta")
    if not result.get("ok"):
        return result
    for col in result["columns"]:
        name = col["name"]
        if var_labels.get(name):
            col["label"] = var_labels[name]
        if name in value_labels:
            col["value_labels"] = {str(k): v for k, v in value_labels[name].items()}
            col["dtype"] = "category"
    return result


# ---------------------------------------------------------------------------
# JSON
# ---------------------------------------------------------------------------


def read_json(path: str) -> dict:
    """Read a JSON file. Accepts two shapes:
    (a) array of objects: [{col1: v, col2: v}, ...]
    (b) object of arrays: {col1: [v, v, ...], col2: [v, v, ...]}
    Anything else returns an error.
    """
    p = _check_path(path)
    if isinstance(p, dict):
        return p
    try:
        raw = p.read_text(encoding="utf-8")
        data = _json.loads(raw)
    except Exception as e:
        return {"ok": False, "error": f"Could not parse JSON: {_userize(e)}"}

    try:
        if isinstance(data, list) and (not data or isinstance(data[0], dict)):
            df = pd.DataFrame(data)
        elif isinstance(data, dict) and all(isinstance(v, list) for v in data.values()):
            df = pd.DataFrame(data)
        else:
            return {
                "ok": False,
                "error": "JSON must be an array of objects or an object of arrays.",
            }
    except Exception as e:
        return {"ok": False, "error": f"Could not coerce JSON to a table: {_userize(e)}"}
    return _finalize(df, str(p), "json")


# ---------------------------------------------------------------------------
# Internals
# ---------------------------------------------------------------------------


def _finalize(df: "pd.DataFrame", path: str, fmt: str) -> dict:
    """Common path: take a DataFrame and produce the normalized dict."""
    if df is None:
        return {"ok": False, "error": "File parsed to empty content."}
    warnings: list[str] = []
    if df.shape[1] == 0:
        return {"ok": False, "error": "No columns detected. Check the delimiter or header row."}
    if df.shape[0] == 0:
        warnings.append("File has 0 data rows.")

    columns = []
    for col_name in df.columns:
        series = df[col_name]
        col_info: dict[str, Any] = {
            "name": str(col_name),
            "dtype": _infer_dtype(series),
            "n_missing": int(series.isna().sum()),
            "label": None,
            "value_labels": None,
        }
        if col_info["dtype"] != "text":
            try:
                col_info["n_unique"] = int(series.dropna().nunique())
            except Exception:
                col_info["n_unique"] = 0
        columns.append(col_info)

    # Preview rows: first PREVIEW_ROW_CAP. Convert to plain JSON-friendly
    # values (NaN -> None, numpy ints/floats -> Python ints/floats).
    preview = []
    for _, row in df.head(PREVIEW_ROW_CAP).iterrows():
        preview.append({str(k): _jsonify(v) for k, v in row.items()})

    return {
        "ok": True,
        "format": fmt,
        "path": path,
        "columns": columns,
        "rows_preview": preview,
        "n_rows_total": int(df.shape[0]),
        "n_cols": int(df.shape[1]),
        "sheets": None,
        "warnings": warnings,
    }


def _infer_dtype(series: "pd.Series") -> str:
    """Pick numeric / category / boolean / datetime / text from a series."""
    s = series.dropna()
    if s.empty:
        return "text"

    # pandas categorical (use isinstance, not the deprecated helper)
    if isinstance(series.dtype, pd.CategoricalDtype):
        return "category"
    # datetime
    if pd.api.types.is_datetime64_any_dtype(series):
        return "datetime"
    # boolean
    if pd.api.types.is_bool_dtype(series):
        return "boolean"
    # numeric
    if pd.api.types.is_numeric_dtype(series):
        # Heuristic: a numeric column with very few distinct integer
        # values is often a coded category. We DON'T relabel it here —
        # let the user decide via the role picker. Return numeric.
        return "numeric"

    # Object dtype: try numeric coercion first
    coerced = pd.to_numeric(s, errors="coerce")
    if coerced.notna().all():
        return "numeric"

    # 2 unique values, all True/False-ish -> boolean
    uniq = set(str(v).strip().lower() for v in s.unique())
    if uniq.issubset({"true", "false", "0", "1", "yes", "no", "y", "n", "t", "f"}) and len(uniq) == 2:
        return "boolean"

    # Below the category threshold -> category
    if s.nunique() <= CATEGORY_THRESHOLD:
        return "category"

    return "text"


def _jsonify(v: Any) -> Any:
    """Convert a single cell value to something json.dumps can handle."""
    if v is None:
        return None
    # Pandas / numpy NaN
    try:
        if isinstance(v, float) and math.isnan(v):
            return None
    except Exception:
        pass
    # pandas NA
    if v is pd.NA:
        return None
    if hasattr(v, "isoformat"):
        try:
            return v.isoformat()
        except Exception:
            pass
    # numpy scalars
    if hasattr(v, "item"):
        try:
            return v.item()
        except Exception:
            pass
    if isinstance(v, (int, float, str, bool)):
        return v
    return str(v)


def _check_path(path: str) -> "Path | dict":
    """Validate a filesystem path. Returns the Path on success, an error
    dict on failure."""
    if not path:
        return {"ok": False, "error": "No file path was provided."}
    p = Path(path).expanduser()
    if not p.exists():
        return {"ok": False, "error": f"File not found: {p}"}
    if not p.is_file():
        return {"ok": False, "error": f"Not a regular file: {p}"}
    try:
        if p.stat().st_size == 0:
            return {"ok": False, "error": "File is empty."}
    except OSError as e:
        return {"ok": False, "error": f"Could not stat file: {_userize(e)}"}
    return p


def _userize(e: BaseException) -> str:
    """Strip Python tracebacks down to one user-friendly line."""
    msg = str(e) or type(e).__name__
    return msg.splitlines()[0]
