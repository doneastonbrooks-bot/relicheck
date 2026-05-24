#!/usr/bin/env python3
"""
Verify the site's Pre/Post + test-retest analysis on
test_data/sample-test-retest-engagement.xlsx against known-good Python
references. Run after any AI edit to app-2026.html's pre/post code.

    cd <repo root>
    python3 test_data/verify_test_retest.py
"""
import json, sys
from pathlib import Path
import numpy as np
import pandas as pd
from scipy import stats

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "sample-test-retest-engagement.xlsx"
EXPECTED = json.loads((ROOT / "test_retest.expected.json").read_text())
TOL = 0.01


def check(label, actual, expected, tol=TOL):
    ok = abs(actual - expected) <= tol
    print(f"  [{'OK ' if ok else 'FAIL'}] {label:<40} expected {expected:>7.3f}   got {actual:>7.3f}")
    return ok


def main() -> int:
    df = pd.read_excel(DATA, sheet_name=EXPECTED["sheet"])
    id_col = EXPECTED["id_column"]
    wave_col = EXPECTED["wave_column"]
    w1_val, w2_val = EXPECTED["wave_values"]
    items = list(EXPECTED["per_item_test_retest_pearson_r"].keys())

    w1 = df[df[wave_col] == w1_val].set_index(id_col)[items]
    w2 = df[df[wave_col] == w2_val].set_index(id_col)[items]
    common = w1.index.intersection(w2.index)
    w1 = w1.loc[common]
    w2 = w2.loc[common]
    t1 = w1.mean(axis=1)
    t2 = w2.mean(axis=1)

    print(f"=== {EXPECTED['dataset']}  (N pairs={len(common)}, k items={len(items)}) ===\n")
    passed = []
    print("Scale total:")
    passed.append(check("Time 1 mean", t1.mean(), EXPECTED["scale_total"]["time_1"]["mean"]))
    passed.append(check("Time 1 SD",   t1.std(ddof=1), EXPECTED["scale_total"]["time_1"]["sd"]))
    passed.append(check("Time 2 mean", t2.mean(), EXPECTED["scale_total"]["time_2"]["mean"]))
    passed.append(check("Time 2 SD",   t2.std(ddof=1), EXPECTED["scale_total"]["time_2"]["sd"]))

    print("\nPaired t-test:")
    t, p = stats.ttest_rel(t2, t1)
    diff = t2 - t1
    dz = diff.mean() / diff.std(ddof=1)
    passed.append(check("t statistic", t, EXPECTED["paired_t_test_scale_total"]["t_statistic"]))
    passed.append(check("Cohen's dz",  dz, EXPECTED["paired_t_test_scale_total"]["cohens_dz"]))

    print("\nTest-retest reliability:")
    r_tot, _ = stats.pearsonr(t1, t2)
    passed.append(check("Pearson r (scale total)", r_tot,
                        EXPECTED["test_retest_reliability"]["pearson_r_scale_total"]))

    # ICC(2,1) two-way random absolute agreement, single rating
    n = len(common); k = 2
    y = np.column_stack([t1.values, t2.values])
    grand = y.mean()
    MSr = (k * ((y.mean(axis=1) - grand) ** 2).sum()) / (n - 1)
    MSc = (n * ((y.mean(axis=0) - grand) ** 2).sum()) / (k - 1)
    SS_within = ((y - y.mean(axis=1, keepdims=True)) ** 2).sum()
    SS_e = SS_within - n * ((y.mean(axis=0) - grand) ** 2).sum()
    MSe = SS_e / ((n - 1) * (k - 1))
    icc = (MSr - MSe) / (MSr + (k - 1) * MSe + k * (MSc - MSe) / n)
    passed.append(check("ICC(2,1) absolute agreement", icc,
                        EXPECTED["test_retest_reliability"]["icc_2_1_absolute_agreement"]))

    print("\nPer-item test-retest correlations:")
    for item, exp_r in EXPECTED["per_item_test_retest_pearson_r"].items():
        r, _ = stats.pearsonr(w1[item], w2[item])
        passed.append(check(item[:38], r, exp_r))

    print()
    n_pass = sum(passed); n_total = len(passed)
    if n_pass == n_total:
        print(f"All {n_total} checks passed.")
        return 0
    print(f"{n_total - n_pass} / {n_total} checks FAILED.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
