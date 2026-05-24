"""Phase 3 ingest tests.

Each test generates a tiny synthetic file in a tmp path, runs it
through the ingest module, and asserts:
- ok is True
- column count
- row count
- dtype inference for at least one column
- format-specific extras (sheet list, variable labels, value labels)
"""

from __future__ import annotations

import json
import math
from pathlib import Path

import pandas as pd
import pytest

from mm_engine.ingest import (
    sniff,
    read_csv,
    read_excel,
    read_spss,
    read_stata,
    read_json,
)


# ---------------------------------------------------------------------------
# Shared synthetic dataset
# ---------------------------------------------------------------------------

def _synth_df() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "id": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            "score": [3.2, 4.1, 2.8, 4.5, 3.9, 4.0, 3.5, 4.2, 3.7, 4.8],
            "arm": ["A", "B", "A", "B", "A", "B", "A", "B", "A", "B"],
            "completed": [True, True, False, True, True, False, True, True, True, False],
            "note": [
                "lorem", "ipsum", "dolor", "sit", "amet",
                "consectetur", "adipiscing", "elit", "sed", "do",
            ],
        }
    )


# ---------------------------------------------------------------------------
# CSV / TSV
# ---------------------------------------------------------------------------


def test_csv_roundtrip(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth.csv"
    df.to_csv(p, index=False)
    r = read_csv(str(p))
    assert r["ok"] is True
    assert r["format"] == "csv"
    assert r["n_rows_total"] == 10
    assert r["n_cols"] == 5
    names = [c["name"] for c in r["columns"]]
    assert names == ["id", "score", "arm", "completed", "note"]
    dtypes = {c["name"]: c["dtype"] for c in r["columns"]}
    assert dtypes["id"] == "numeric"
    assert dtypes["score"] == "numeric"
    assert dtypes["arm"] == "category"
    assert dtypes["completed"] == "boolean"
    # `note` has 10 unique strings — at or below threshold, so category
    assert dtypes["note"] in ("category", "text")
    # Preview clamps at 50 rows
    assert len(r["rows_preview"]) == 10


def test_tsv_via_sniff(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth.tsv"
    df.to_csv(p, sep="\t", index=False)
    r = sniff(str(p))
    assert r["ok"] is True
    assert r["format"] == "tsv"
    assert r["n_cols"] == 5


def test_csv_missing_file():
    r = read_csv("/nonexistent/file.csv")
    assert r["ok"] is False
    assert "not found" in r["error"].lower()


def test_csv_empty_file(tmp_path: Path):
    p = tmp_path / "empty.csv"
    p.write_text("")
    r = read_csv(str(p))
    assert r["ok"] is False


# ---------------------------------------------------------------------------
# Excel
# ---------------------------------------------------------------------------


def test_excel_single_sheet(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth.xlsx"
    df.to_excel(p, index=False)
    r = read_excel(str(p))
    assert r["ok"] is True
    assert r["format"] == "xlsx"
    assert r["n_rows_total"] == 10
    assert r["n_cols"] == 5
    assert r["sheets"] is not None
    assert len(r["sheets"]) == 1


def test_excel_multi_sheet_needs_picker(tmp_path: Path):
    p = tmp_path / "multi.xlsx"
    with pd.ExcelWriter(p) as w:
        _synth_df().to_excel(w, sheet_name="alpha", index=False)
        _synth_df().head(3).to_excel(w, sheet_name="beta", index=False)
    r = read_excel(str(p))
    assert r["ok"] is True
    assert r.get("needs_sheet_pick") is True
    assert set(r["sheets"]) == {"alpha", "beta"}
    assert r["n_rows_total"] == 0  # no rows yet — caller must re-call with sheet


def test_excel_multi_sheet_explicit(tmp_path: Path):
    p = tmp_path / "multi.xlsx"
    with pd.ExcelWriter(p) as w:
        _synth_df().to_excel(w, sheet_name="alpha", index=False)
        _synth_df().head(3).to_excel(w, sheet_name="beta", index=False)
    r = read_excel(str(p), sheet="beta")
    assert r["ok"] is True
    assert r["n_rows_total"] == 3
    assert r["sheet"] == "beta"


# ---------------------------------------------------------------------------
# SPSS .sav (requires pyreadstat)
# ---------------------------------------------------------------------------


def test_spss_with_labels(tmp_path: Path):
    pyreadstat = pytest.importorskip("pyreadstat")
    df = _synth_df()[["id", "score", "arm"]]
    p = tmp_path / "synth.sav"
    pyreadstat.write_sav(
        df,
        str(p),
        column_labels=["Subject ID", "Outcome score", "Treatment arm"],
        variable_value_labels={"arm": {"A": "Control", "B": "Treatment"}},
    )
    r = read_spss(str(p))
    assert r["ok"] is True
    assert r["format"] == "sav"
    assert r["n_rows_total"] == 10
    cols = {c["name"]: c for c in r["columns"]}
    # Variable labels preserved
    assert cols["score"]["label"] == "Outcome score"
    # Value labels preserved on arm, and arm is now category
    assert cols["arm"]["dtype"] == "category"
    assert cols["arm"]["value_labels"] == {"A": "Control", "B": "Treatment"}


# ---------------------------------------------------------------------------
# Stata .dta
# ---------------------------------------------------------------------------


def test_stata_with_var_labels(tmp_path: Path):
    df = _synth_df()[["id", "score", "arm"]]
    p = tmp_path / "synth.dta"
    df.to_stata(
        p,
        write_index=False,
        variable_labels={"id": "Subject ID", "score": "Outcome score", "arm": "Treatment arm"},
    )
    r = read_stata(str(p))
    assert r["ok"] is True
    assert r["format"] == "dta"
    cols = {c["name"]: c for c in r["columns"]}
    assert cols["score"]["label"] == "Outcome score"


# ---------------------------------------------------------------------------
# JSON
# ---------------------------------------------------------------------------


def test_json_array_of_objects(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth.json"
    p.write_text(df.to_json(orient="records"))
    r = read_json(str(p))
    assert r["ok"] is True
    assert r["format"] == "json"
    assert r["n_rows_total"] == 10
    assert r["n_cols"] == 5


def test_json_object_of_arrays(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth_cols.json"
    p.write_text(json.dumps({col: df[col].tolist() for col in df.columns}))
    r = read_json(str(p))
    assert r["ok"] is True
    assert r["n_rows_total"] == 10
    assert r["n_cols"] == 5


def test_json_bad_shape(tmp_path: Path):
    p = tmp_path / "bad.json"
    p.write_text('"just a string"')
    r = read_json(str(p))
    assert r["ok"] is False


# ---------------------------------------------------------------------------
# sniff() dispatch
# ---------------------------------------------------------------------------


def test_sniff_dispatches_by_extension(tmp_path: Path):
    df = _synth_df()

    csv_p = tmp_path / "x.csv"; df.to_csv(csv_p, index=False)
    xlsx_p = tmp_path / "x.xlsx"; df.to_excel(xlsx_p, index=False)
    json_p = tmp_path / "x.json"; json_p.write_text(df.to_json(orient="records"))

    assert sniff(str(csv_p))["format"] == "csv"
    assert sniff(str(xlsx_p))["format"] == "xlsx"
    assert sniff(str(json_p))["format"] == "json"


def test_sniff_unsupported_extension(tmp_path: Path):
    p = tmp_path / "thing.parquet"
    p.write_text("not really parquet")
    r = sniff(str(p))
    assert r["ok"] is False
    assert "Unsupported" in r["error"]


# ---------------------------------------------------------------------------
# Preview row cap
# ---------------------------------------------------------------------------


def test_preview_row_cap(tmp_path: Path):
    # Make a 200-row CSV
    big = pd.DataFrame({"x": range(200), "y": [i * 0.5 for i in range(200)]})
    p = tmp_path / "big.csv"
    big.to_csv(p, index=False)
    r = read_csv(str(p))
    assert r["ok"] is True
    assert r["n_rows_total"] == 200
    assert len(r["rows_preview"]) == 50  # capped


# ---------------------------------------------------------------------------
# Roundtrip preview values are JSON-friendly
# ---------------------------------------------------------------------------


def test_preview_values_are_json_safe(tmp_path: Path):
    df = _synth_df()
    p = tmp_path / "synth.csv"
    df.to_csv(p, index=False)
    r = read_csv(str(p))
    # json.dumps must not raise
    json.dumps(r["rows_preview"])
