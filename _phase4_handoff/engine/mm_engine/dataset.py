"""In-memory dataset cache for MM Studio (Phase 4).

When ingest succeeds, the full DataFrame is stashed here under a UUID
so subsequent analysis calls can read whole columns without the webview
having to ship every row through stdin/stdout. The cache is process-
local (one cache per sidecar process); when the sidecar restarts
(app relaunch), the cache is empty and the user re-opens their file.

Public surface:

    put_dataset(df, format, path)        -> ingest_id (uuid str)
    get_dataset(ingest_id)               -> dict | None
    get_column(ingest_id, column_name)   -> list | None
    drop_dataset(ingest_id)              -> bool
    list_datasets()                      -> [ingest_id, ...]

Storage shape per entry:
    {
        "df": pd.DataFrame,         # full data
        "format": str,
        "path": str,
        "n_rows": int,
        "n_cols": int,
    }

This module does no IO. ingest.py decides when to call put_dataset.
"""

from __future__ import annotations

import threading
import uuid
from typing import Any

import pandas as pd

# Bounded cache: we keep at most N datasets to avoid runaway memory when
# users open file after file in one session. Oldest entries fall out
# first. For a desktop app this is more than enough; serious users will
# rarely have more than two or three datasets open simultaneously.
_CAP = 8

# Module-global cache. Wrap in a lock because the sidecar is
# single-threaded today but won't always be.
_lock = threading.Lock()
_store: "dict[str, dict[str, Any]]" = {}
_order: list[str] = []  # insertion order, oldest first


def put_dataset(df: "pd.DataFrame", fmt: str, path: str) -> str:
    """Cache a DataFrame and return its ingest_id."""
    iid = str(uuid.uuid4())
    entry = {
        "df": df,
        "format": fmt,
        "path": path,
        "n_rows": int(df.shape[0]),
        "n_cols": int(df.shape[1]),
    }
    with _lock:
        _store[iid] = entry
        _order.append(iid)
        while len(_order) > _CAP:
            old = _order.pop(0)
            _store.pop(old, None)
    return iid


def get_dataset(ingest_id: str) -> "dict | None":
    if not ingest_id:
        return None
    with _lock:
        return _store.get(ingest_id)


def get_column(ingest_id: str, column_name: str) -> "list | None":
    """Return the full column as a JSON-friendly list, or None if either
    the dataset or the column is missing."""
    entry = get_dataset(ingest_id)
    if entry is None:
        return None
    df = entry["df"]
    if column_name not in df.columns:
        return None
    series = df[column_name]
    # Convert to list with NaN -> None so downstream stats code can
    # treat missing values the same way it would for CSV ingest.
    out = []
    for v in series.tolist():
        if isinstance(v, float):
            try:
                import math as _m
                if _m.isnan(v):
                    out.append(None)
                    continue
            except Exception:
                pass
        out.append(v)
    return out


def drop_dataset(ingest_id: str) -> bool:
    with _lock:
        if ingest_id in _store:
            _store.pop(ingest_id, None)
            try:
                _order.remove(ingest_id)
            except ValueError:
                pass
            return True
    return False


def list_datasets() -> list:
    """Return summary metadata for every cached dataset."""
    with _lock:
        return [
            {
                "ingest_id": iid,
                "format": entry["format"],
                "path": entry["path"],
                "n_rows": entry["n_rows"],
                "n_cols": entry["n_cols"],
            }
            for iid, entry in _store.items()
        ]


def clear_all() -> None:
    """Test helper. Resets the cache to empty."""
    with _lock:
        _store.clear()
        _order.clear()
