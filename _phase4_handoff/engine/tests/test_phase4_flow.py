"""Phase 4 round-trip: ingest -> suggest -> run, using the cached dataset.

These tests skip the IPC stdin/stdout layer (covered by other tests) and
exercise the in-process call chain that the IPC handler ends up calling.
"""

from __future__ import annotations

from pathlib import Path

import pandas as pd
import pytest

from mm_engine import ingest as ingest_mod
from mm_engine import dataset as ds
from mm_engine.suggest import suggest_test
from mm_engine import tests as stats


def _make_csv(tmp_path: Path) -> Path:
    df = pd.DataFrame(
        {
            "id": list(range(1, 21)),
            "score_pre":  [3.2,4.1,2.8,4.5,3.9,4.0,3.5,4.2,3.7,4.8,
                           3.0,4.0,2.9,4.6,3.8,4.1,3.4,4.3,3.6,4.7],
            "score_post": [4.1,4.8,3.5,5.0,4.7,4.6,4.2,4.9,4.3,5.1,
                           4.0,4.7,3.6,5.0,4.8,4.5,4.1,5.0,4.4,5.2],
            "arm":        ["A","B","A","B","A","B","A","B","A","B",
                           "A","B","A","B","A","B","A","B","A","B"],
            "completed":  [True]*15 + [False]*5,
        }
    )
    p = tmp_path / "synth20.csv"
    df.to_csv(p, index=False)
    return p


def test_ingest_populates_cache(tmp_path: Path):
    ds.clear_all()
    p = _make_csv(tmp_path)
    r = ingest_mod.read_csv(str(p))
    assert r["ok"] is True
    assert "ingest_id" in r and r["ingest_id"]
    # Cache hit
    entry = ds.get_dataset(r["ingest_id"])
    assert entry is not None
    assert entry["n_rows"] == 20
    assert entry["n_cols"] == 5


def test_get_column_returns_full_data(tmp_path: Path):
    ds.clear_all()
    p = _make_csv(tmp_path)
    r = ingest_mod.read_csv(str(p))
    iid = r["ingest_id"]
    col = ds.get_column(iid, "score_pre")
    assert col is not None
    assert len(col) == 20
    assert col[0] == 3.2 and col[19] == 4.7


def test_pearson_via_cache(tmp_path: Path):
    ds.clear_all()
    p = _make_csv(tmp_path)
    r = ingest_mod.read_csv(str(p))
    iid = r["ingest_id"]
    a = ds.get_column(iid, "score_pre")
    b = ds.get_column(iid, "score_post")
    result = stats.pearson(a, b)
    assert result["ok"] is True
    assert result["n_total"] == 20
    assert 0.7 < result["statistic"] < 1.0  # positively correlated by construction


def test_t_test_via_cache(tmp_path: Path):
    ds.clear_all()
    p = _make_csv(tmp_path)
    r = ingest_mod.read_csv(str(p))
    iid = r["ingest_id"]
    a = ds.get_column(iid, "score_pre")
    b = ds.get_column(iid, "arm")
    result = stats.t_test(a, b)
    assert result["ok"] is True
    assert result["n_total"] == 20


def test_unknown_column_returns_none(tmp_path: Path):
    ds.clear_all()
    p = _make_csv(tmp_path)
    r = ingest_mod.read_csv(str(p))
    iid = r["ingest_id"]
    assert ds.get_column(iid, "no_such_column") is None
    assert ds.get_column("no-such-dataset", "score_pre") is None


def test_cache_lru_eviction(tmp_path: Path):
    ds.clear_all()
    ids = []
    for i in range(10):
        df = pd.DataFrame({"x": [1, 2, 3]})
        iid = ds.put_dataset(df, "csv", f"/tmp/x{i}.csv")
        ids.append(iid)
    # _CAP = 8, so oldest 2 should be evicted
    assert ds.get_dataset(ids[0]) is None
    assert ds.get_dataset(ids[1]) is None
    assert ds.get_dataset(ids[2]) is not None
    assert ds.get_dataset(ids[9]) is not None
    assert len(ds.list_datasets()) == 8
