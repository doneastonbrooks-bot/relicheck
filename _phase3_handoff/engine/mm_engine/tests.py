"""The four classical tests, ported from api/_stats.php.

Each function returns a dict in the same shape the PHP helpers return:

    {
        "ok": True,
        "test_name": "chi_square" | "t_test" | "anova" | "pearson",
        "statistic": float,
        "df1": float | None,
        "df2": float | None,
        "p_value": float,
        "effect_size": float | None,
        "effect_label": "cramers_v" | "cohens_d" | "eta_squared" | "r_squared" | None,
        "n_total": int,
        "summary": str,
        "details": dict,
    }

On bad input: {"ok": False, "error": "<reason>"}.

Scipy provides the p-value functions (chi2.sf, t.sf, f.sf). Test
statistics, degrees of freedom, and effect sizes are computed by hand
using the same arithmetic the PHP uses, so they agree to floating-point
precision on the parity tests.
"""

from __future__ import annotations

from typing import Any
import math

import numpy as np
from scipy import stats as _sps


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _format_p(p: float) -> str:
    """Match stats_format_p() in _stats.php."""
    if p < 0.0001:
        return "<.0001"
    if p < 0.001:
        return "<.001"
    return f"{p:.3f}"


def _is_numeric(v: Any) -> bool:
    """Match PHP is_numeric(): accepts numbers and numeric strings."""
    if isinstance(v, bool):
        return False  # PHP is_numeric(true) is true but the PHP loop treats bool inputs the same as numeric — keep stricter here
    if isinstance(v, (int, float)):
        return not (isinstance(v, float) and math.isnan(v))
    if isinstance(v, str):
        s = v.strip()
        if s == "":
            return False
        try:
            float(s)
            return True
        except ValueError:
            return False
    return False


def _to_float(v: Any) -> float:
    return float(v)


# ---------------------------------------------------------------------------
# Chi-square independence
# ---------------------------------------------------------------------------

def chi_square(a: list, b: list) -> dict:
    n = min(len(a), len(b))
    rows: dict[str, int] = {}
    cols: dict[str, int] = {}
    cell: dict[tuple[str, str], int] = {}
    for i in range(n):
        r = "" if a[i] is None else str(a[i])
        c = "" if b[i] is None else str(b[i])
        if r == "" or c == "":
            continue
        rows[r] = rows.get(r, 0) + 1
        cols[c] = cols.get(c, 0) + 1
        cell[(r, c)] = cell.get((r, c), 0) + 1

    N = sum(rows.values())
    if N < 5 or len(rows) < 2 or len(cols) < 2:
        row_vals = list(rows.keys()) or []
        col_vals = list(cols.keys()) or []
        pred = "(none)" if not row_vals else ", ".join(v if v != "" else "<blank>" for v in row_vals)
        out = "(none)" if not col_vals else ", ".join(v if v != "" else "<blank>" for v in col_vals)
        detail = f"Predictor distinct values: {pred}. Outcome distinct values: {out}. N = {N}."
        return {"ok": False, "error": f"Chi-square needs at least 2 categories on each side and N >= 5. {detail}"}

    chi2 = 0.0
    for r, rn in rows.items():
        for c, cn in cols.items():
            obs = cell.get((r, c), 0)
            exp = (rn * cn) / N
            if exp > 0.0:
                chi2 += (obs - exp) ** 2 / exp

    df = (len(rows) - 1) * (len(cols) - 1)
    p = float(_sps.chi2.sf(chi2, df)) if df > 0 else 1.0
    k = min(len(rows), len(cols))
    cramers = math.sqrt(chi2 / (N * (k - 1))) if k > 1 else None

    summary = (
        f"Chi-square(df={df}) = {chi2:.2f}, p = {_format_p(p)}, "
        f"Cramer V = {cramers if cramers is not None else 0.0:.2f}, N = {N}."
    )
    return {
        "ok": True,
        "test_name": "chi_square",
        "statistic": chi2,
        "df1": float(df),
        "df2": None,
        "p_value": p,
        "effect_size": cramers,
        "effect_label": "cramers_v",
        "n_total": N,
        "summary": summary,
        "details": {
            "rows": rows,
            "cols": cols,
            # JSON-friendly contingency: list of (row, col, count)
            "contingency": [{"row": r, "col": c, "n": n_} for (r, c), n_ in cell.items()],
        },
    }


# ---------------------------------------------------------------------------
# Welch's t-test
# ---------------------------------------------------------------------------

def t_test(values: list, groups: list) -> dict:
    n = min(len(values), len(groups))
    by_group: dict[str, list[float]] = {}
    for i in range(n):
        v = values[i]
        g = "" if groups[i] is None else str(groups[i])
        if g == "" or not _is_numeric(v):
            continue
        by_group.setdefault(g, []).append(_to_float(v))

    if len(by_group) != 2:
        return {"ok": False, "error": f"Independent t-test needs exactly 2 groups (got {len(by_group)})."}

    keys = list(by_group.keys())
    a, b = by_group[keys[0]], by_group[keys[1]]
    na, nb = len(a), len(b)
    if na < 2 or nb < 2:
        return {"ok": False, "error": "Each group needs at least 2 observations."}

    ma = sum(a) / na
    mb = sum(b) / nb
    va = _variance(a, ma)
    vb = _variance(b, mb)
    if va == 0.0 and vb == 0.0:
        return {"ok": False, "error": "No variance in either group."}

    se = math.sqrt(va / na + vb / nb)
    if se == 0.0:
        return {"ok": False, "error": "Standard error is zero."}

    t = (ma - mb) / se
    # Welch-Satterthwaite df, exact PHP arithmetic
    df = ((va / na + vb / nb) ** 2) / (
        ((va / na) ** 2) / max(na - 1, 1) + ((vb / nb) ** 2) / max(nb - 1, 1)
    )
    p = float(2.0 * _sps.t.sf(abs(t), df))

    sp = math.sqrt(((na - 1) * va + (nb - 1) * vb) / max(na + nb - 2, 1))
    d = (ma - mb) / sp if sp > 0.0 else None
    N = na + nb
    d_str = "n/a" if d is None else f"{d:.2f}"
    summary = (
        f"Welch t(df={df:.1f}) = {t:.2f}, p = {_format_p(p)}, "
        f"Cohen d = {d_str}, N = {N}. "
        f"{keys[0]} mean = {ma:.2f} (n={na}), {keys[1]} mean = {mb:.2f} (n={nb})."
    )
    return {
        "ok": True,
        "test_name": "t_test",
        "statistic": t,
        "df1": df,
        "df2": None,
        "p_value": p,
        "effect_size": d,
        "effect_label": "cohens_d",
        "n_total": N,
        "summary": summary,
        "details": {
            "groups": {
                keys[0]: {"n": na, "mean": ma, "var": va},
                keys[1]: {"n": nb, "mean": mb, "var": vb},
            }
        },
    }


# ---------------------------------------------------------------------------
# One-way ANOVA
# ---------------------------------------------------------------------------

def anova(values: list, groups: list) -> dict:
    n = min(len(values), len(groups))
    by_group: dict[str, list[float]] = {}
    for i in range(n):
        v = values[i]
        g = "" if groups[i] is None else str(groups[i])
        if g == "" or not _is_numeric(v):
            continue
        by_group.setdefault(g, []).append(_to_float(v))

    if len(by_group) < 3:
        return {"ok": False, "error": f"One-way ANOVA needs at least 3 groups (got {len(by_group)})."}

    grand_sum = 0.0
    grand_n = 0
    stats_: dict[str, dict] = {}
    for g, arr in by_group.items():
        cnt = len(arr)
        if cnt < 2:
            return {"ok": False, "error": f'Each group needs at least 2 observations (group "{g}" has {cnt}).'}
        mean = sum(arr) / cnt
        stats_[g] = {"n": cnt, "mean": mean}
        grand_sum += sum(arr)
        grand_n += cnt

    if grand_n < 6:
        return {"ok": False, "error": "Need at least 6 observations total."}

    grand_mean = grand_sum / grand_n
    ss_between = 0.0
    ss_within = 0.0
    for g, arr in by_group.items():
        cnt = stats_[g]["n"]
        mean = stats_[g]["mean"]
        ss_between += cnt * ((mean - grand_mean) ** 2)
        for x in arr:
            ss_within += (x - mean) ** 2

    df_between = len(by_group) - 1
    df_within = grand_n - len(by_group)
    if df_within < 1 or ss_within == 0.0:
        return {"ok": False, "error": "No within-group variance."}

    ms_between = ss_between / df_between
    ms_within = ss_within / df_within
    F = ms_between / ms_within
    p = float(_sps.f.sf(F, df_between, df_within))
    eta_sq = ss_between / (ss_between + ss_within) if (ss_between + ss_within) > 0.0 else None

    summary = (
        f"One-way ANOVA F({df_between}, {df_within}) = {F:.2f}, "
        f"p = {_format_p(p)}, eta-squared = {eta_sq if eta_sq is not None else 0.0:.3f}, "
        f"N = {grand_n}, groups = {len(by_group)}."
    )
    return {
        "ok": True,
        "test_name": "anova",
        "statistic": F,
        "df1": float(df_between),
        "df2": float(df_within),
        "p_value": p,
        "effect_size": eta_sq,
        "effect_label": "eta_squared",
        "n_total": grand_n,
        "summary": summary,
        "details": {"groups": stats_, "grand_mean": grand_mean},
    }


# ---------------------------------------------------------------------------
# Pearson correlation
# ---------------------------------------------------------------------------

def pearson(x: list, y: list) -> dict:
    n = min(len(x), len(y))
    xs: list[float] = []
    ys: list[float] = []
    for i in range(n):
        if _is_numeric(x[i]) and _is_numeric(y[i]):
            xs.append(_to_float(x[i]))
            ys.append(_to_float(y[i]))
    N = len(xs)
    if N < 3:
        return {"ok": False, "error": "Pearson r needs at least 3 paired observations."}

    mx = sum(xs) / N
    my = sum(ys) / N
    sxy = sxx = syy = 0.0
    for i in range(N):
        dx = xs[i] - mx
        dy = ys[i] - my
        sxy += dx * dy
        sxx += dx * dx
        syy += dy * dy

    if sxx == 0.0 and syy == 0.0:
        return {"ok": False, "error": "Neither variable has any variance - every value is the same."}
    if sxx == 0.0:
        return {"ok": False, "error": f"The predictor variable has no variance - every value is the same (mean = {mx:.2f})."}
    if syy == 0.0:
        return {"ok": False, "error": f"The outcome variable has no variance - every value is the same (mean = {my:.2f})."}

    r = sxy / math.sqrt(sxx * syy)
    if r >= 1.0:
        r = 0.999999999
    if r <= -1.0:
        r = -0.999999999

    df = N - 2
    t = r * math.sqrt(df / (1.0 - r * r))
    p = float(2.0 * _sps.t.sf(abs(t), df))

    summary = (
        f"Pearson r = {r:.2f} (r-squared = {r*r:.3f}), "
        f"t({df}) = {t:.2f}, p = {_format_p(p)}, N = {N}."
    )
    return {
        "ok": True,
        "test_name": "pearson",
        "statistic": r,
        "df1": float(df),
        "df2": None,
        "p_value": p,
        "effect_size": r * r,
        "effect_label": "r_squared",
        "n_total": N,
        "summary": summary,
        "details": {"mean_x": mx, "mean_y": my},
    }


# ---------------------------------------------------------------------------
# Variance helper (sample variance, n-1 denominator) — mirrors stats_variance()
# ---------------------------------------------------------------------------

def _variance(arr: list[float], mean: float | None = None) -> float:
    n = len(arr)
    if n < 2:
        return 0.0
    if mean is None:
        mean = sum(arr) / n
    s = 0.0
    for x in arr:
        s += (x - mean) ** 2
    return s / (n - 1)
