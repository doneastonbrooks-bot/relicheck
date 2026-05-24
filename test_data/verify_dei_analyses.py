#!/usr/bin/env python3
"""
Verify that the site's quantitative analyses on test_data/dei.xlsx still
produce the same values they did when the platform was known to be working
(2026-05-23). Run this after any AI/manual edit to app-2026.html's analysis
code — if numbers drift, something broke.

Usage:
    cd <repo root>
    pip install pandas numpy scipy openpyxl    # if needed
    python3 test_data/verify_dei_analyses.py

Prints a side-by-side report and exits non-zero if anything drifts beyond
rounding tolerance.
"""

import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd
from scipy import stats

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "dei.xlsx"
EXPECTED = json.loads((ROOT / "dei.expected.json").read_text())
TOL = 0.01  # rounding tolerance (site rounds to 2 decimals in places)


def cronbach_alpha(X: np.ndarray) -> float:
    k = X.shape[1]
    item_vars = X.var(axis=0, ddof=1).sum()
    total_var = X.sum(axis=1).var(ddof=1)
    return (k / (k - 1)) * (1 - item_vars / total_var)


def kmo_overall(X: np.ndarray) -> float:
    R = np.corrcoef(X.T)
    Rinv = np.linalg.inv(R)
    D = np.diag(1.0 / np.sqrt(np.diag(Rinv)))
    P = -D @ Rinv @ D
    np.fill_diagonal(P, 1.0)
    iu = np.triu_indices_from(R, k=1)
    r_sq = (R[iu] ** 2).sum() * 2
    p_sq = (P[iu] ** 2).sum() * 2
    return r_sq / (r_sq + p_sq)


def paf_one_factor(R: np.ndarray, max_iter: int = 50, tol: float = 1e-6):
    # Initial communalities from squared multiple correlations
    k = R.shape[0]
    h2 = np.array([
        R[other, i] @ np.linalg.inv(R[np.ix_(other, other)]) @ R[other, i]
        for i in range(k)
        for other in [[j for j in range(k) if j != i]]
    ])
    loadings = None
    for _ in range(max_iter):
        Rs = R.copy()
        np.fill_diagonal(Rs, h2)
        w, v = np.linalg.eigh(Rs)
        idx = np.argsort(w)[::-1]
        w = w[idx]
        v = v[:, idx]
        if w[0] <= 0:
            break
        loadings = v[:, 0] * np.sqrt(w[0])
        h2_new = loadings ** 2
        if np.max(np.abs(h2 - h2_new)) < tol:
            break
        h2 = h2_new
    return np.abs(loadings)  # absolute value to be sign-agnostic


def check(label: str, actual: float, expected: float, tol: float = TOL) -> bool:
    ok = abs(actual - expected) <= tol
    flag = "OK " if ok else "FAIL"
    print(f"  [{flag}] {label:<32} expected {expected:>7.3f}   got {actual:>7.3f}")
    return ok


def main() -> int:
    df = pd.read_excel(DATA)
    items = EXPECTED["likert_items"]
    X = df[items].dropna()
    Xv = X.values

    print(f"=== {EXPECTED['dataset']}  (N={len(X)}, k={len(items)}) ===\n")

    passed = []

    print("Reliability:")
    passed.append(check("Cronbach alpha",
                        cronbach_alpha(Xv),
                        EXPECTED["reliability"]["cronbach_alpha"]))

    # McDonald omega via 1-factor loadings
    R = np.corrcoef(X.T)
    loadings = paf_one_factor(R)
    sl = loadings.sum()
    omega = sl ** 2 / (sl ** 2 + (1 - loadings ** 2).sum())
    passed.append(check("McDonald omega", omega,
                        EXPECTED["reliability"]["mcdonald_omega"]))

    print("\nDescriptives:")
    for c, exp in EXPECTED["reliability"]["items"].items():
        passed.append(check(f"{c} mean", X[c].mean(), exp["mean"]))
        passed.append(check(f"{c} SD",  X[c].std(ddof=1), exp["sd"]))

    print("\nFactor analysis:")
    passed.append(check("KMO overall", kmo_overall(Xv),
                        EXPECTED["validity_factor_analysis"]["kmo_overall"]))
    var_pct = 100 * (loadings ** 2).sum() / len(loadings)
    passed.append(check("Var explained F1", var_pct,
                        EXPECTED["validity_factor_analysis"]["variance_explained_pct"],
                        tol=0.5))
    for c, exp_l in EXPECTED["validity_factor_analysis"]["loadings_factor_1"].items():
        i = items.index(c)
        passed.append(check(f"loading {c}", loadings[i], exp_l))

    print("\nANOVA (Gender, filtered to N>=3 groups):")
    df['scale_total'] = df[items].mean(axis=1)
    keep = list(EXPECTED["comparison_anova_gender"]["group_means"].keys())
    sub = df[df['Gender'].isin(keep)]
    groups = [sub[sub['Gender'] == g]['scale_total'].values for g in keep]
    F, p = stats.f_oneway(*groups)
    all_y = np.concatenate(groups)
    ss_b = sum(len(g) * (g.mean() - all_y.mean()) ** 2 for g in groups)
    eta2 = ss_b / ((all_y - all_y.mean()) ** 2).sum()
    passed.append(check("F statistic", F,
                        EXPECTED["comparison_anova_gender"]["f_statistic"]))
    passed.append(check("eta-squared", eta2,
                        EXPECTED["comparison_anova_gender"]["eta_squared"]))

    print()
    n_pass = sum(passed)
    n_total = len(passed)
    if n_pass == n_total:
        print(f"All {n_total} checks passed.")
        return 0
    print(f"{n_total - n_pass} / {n_total} checks FAILED.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
