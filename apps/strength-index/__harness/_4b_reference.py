"""§4B Construct Alignment — reference values for the JS harness sanity checks.

Generates two fixtures with fixed numpy seeds, writes the raw datasets to
JSON (so the JS harness consumes byte-identical inputs), and writes the
expected reference values from semopy (ML CFA, equivalent to lavaan) and
factor_analyzer (EFA + Promax rotation).

Run: python3 apps/strength-index/__harness/_4b_reference.py
Outputs (written next to this file):
  fixture_cfa.json     — 2 scales × 4 items × N=500, factor cor 0.3, λ=0.8
  fixture_promax.json  — pooled 8 items, known oblique structure
  expected_cfa.json    — semopy std loadings + CFI + RMSEA per scale
  expected_promax.json — factor_analyzer Promax rotated loadings (signed/scaled)

Tolerances accepted by Phase 1 review (Q7):
  - Standardized loadings: ±0.05 vs ML reference (PAF estimator divergence).
  - CFI: ±0.05 vs ML reference.
  - Promax rotated loadings: ±0.05 vs factor_analyzer reference.

The fixtures here are populated with seeded pseudo-random multivariate normal
draws, then *quantized to 5-point Likert* (the engine's primary input type).
The CFA sanity check exercises the engine against ordinal data, matching how
production runs feed it.
"""

import json
import os

import numpy as np
import pandas as pd
import semopy
from factor_analyzer import FactorAnalyzer

HERE = os.path.dirname(os.path.abspath(__file__))


def to_likert_5(z: np.ndarray) -> np.ndarray:
    """Quantize a standard-normal column to a 5-point Likert via quintile cuts."""
    cuts = [-1.2816, -0.5244, 0.5244, 1.2816]  # symmetric 20/20/20/20/20 quintiles
    out = np.ones_like(z, dtype=int)
    for c in cuts:
        out += (z > c).astype(int)
    return out  # 1..5


def build_cfa_fixture(seed: int = 4242) -> dict:
    """Two scales of 4 items each, factor correlation 0.3, λ=0.8 population."""
    rng = np.random.default_rng(seed)
    N = 500
    # Two correlated factors
    cov_f = np.array([[1.0, 0.3], [0.3, 1.0]])
    F = rng.multivariate_normal([0, 0], cov_f, size=N)  # N×2
    lam = 0.8
    eps_sd = np.sqrt(1 - lam**2)  # ~0.6
    cols = {}
    # Scale A: items A1..A4 load on factor 0
    for k in range(1, 5):
        z = lam * F[:, 0] + rng.normal(0, eps_sd, N)
        cols[f"A{k}"] = to_likert_5(z).tolist()
    # Scale B: items B1..B4 load on factor 1
    for k in range(1, 5):
        z = lam * F[:, 1] + rng.normal(0, eps_sd, N)
        cols[f"B{k}"] = to_likert_5(z).tolist()
    return {
        "seed": seed,
        "N": N,
        "scales": {"A": ["A1", "A2", "A3", "A4"], "B": ["B1", "B2", "B3", "B4"]},
        "columns": cols,
        "population": {"loading": lam, "factor_correlation": 0.3},
    }


def build_promax_fixture(seed: int = 8484) -> dict:
    """Eight items with two-factor oblique structure for Promax rotation check."""
    rng = np.random.default_rng(seed)
    N = 600
    cov_f = np.array([[1.0, 0.4], [0.4, 1.0]])  # genuinely oblique
    F = rng.multivariate_normal([0, 0], cov_f, size=N)
    cols = {}
    # Items I1..I4 primary-load on factor 0 with λ=0.75
    # Items I5..I8 primary-load on factor 1 with λ=0.75
    primary = 0.75
    eps_sd = np.sqrt(1 - primary**2)
    for k in range(1, 5):
        z = primary * F[:, 0] + rng.normal(0, eps_sd, N)
        cols[f"I{k}"] = to_likert_5(z).tolist()
    for k in range(5, 9):
        z = primary * F[:, 1] + rng.normal(0, eps_sd, N)
        cols[f"I{k}"] = to_likert_5(z).tolist()
    return {
        "seed": seed,
        "N": N,
        "items_factor_0": ["I1", "I2", "I3", "I4"],
        "items_factor_1": ["I5", "I6", "I7", "I8"],
        "columns": cols,
        "population": {"primary_loading": primary, "factor_correlation": 0.4},
    }


def cfa_expected(fixture: dict) -> dict:
    """Run semopy ML CFA per scale; return standardized loadings + fit."""
    df = pd.DataFrame(fixture["columns"]).astype(float)
    per_scale = {}
    for scale_name, items in fixture["scales"].items():
        spec = f"F =~ " + " + ".join(items)
        m = semopy.Model(spec)
        m.fit(df[items], obj="MLW")
        # Standardized loadings: semopy's inspect with std_est=True
        ins = m.inspect(std_est=True)
        loads = ins[(ins["op"] == "~") | (ins["op"] == "=~")] if False else ins
        # Pull standardized loadings for the F =~ item rows
        rows = ins[(ins["op"] == "~") | (ins["op"] == "=~")]
        std = {}
        for _, r in ins.iterrows():
            if r["op"] == "~" and r["rval"] == "F":
                std[r["lval"]] = float(r["Est. Std"])
        # Fit indices
        stats = semopy.calc_stats(m)
        cfi = float(stats["CFI"].iloc[0])
        rmsea = float(stats["RMSEA"].iloc[0])
        per_scale[scale_name] = {
            "loadings_std": std,
            "cfi": cfi,
            "rmsea": rmsea,
            "k": len(items),
        }
    return per_scale


def promax_expected(fixture: dict) -> dict:
    items = fixture["items_factor_0"] + fixture["items_factor_1"]
    df = pd.DataFrame(fixture["columns"])[items].astype(float)
    fa = FactorAnalyzer(n_factors=2, rotation="promax", method="principal")
    fa.fit(df)
    loads = fa.loadings_  # shape (n_items, 2)
    phi = fa.phi_ if fa.phi_ is not None else None
    # Match factor ordering deterministically: factor 0 = the one with the
    # higher mean loading on items I1..I4.
    primary0 = float(np.mean(np.abs(loads[:4, 0])))
    primary1 = float(np.mean(np.abs(loads[:4, 1])))
    if primary1 > primary0:
        loads = loads[:, [1, 0]]
        if phi is not None:
            phi = phi[[1, 0], :][:, [1, 0]]
    # Sign alignment: ensure factor 0's mean loading on its primary items is positive
    if np.mean(loads[:4, 0]) < 0:
        loads[:, 0] *= -1
    if np.mean(loads[4:, 1]) < 0:
        loads[:, 1] *= -1
    out = {}
    for i, name in enumerate(items):
        out[name] = {"factor_0": float(loads[i, 0]), "factor_1": float(loads[i, 1])}
    return {
        "items_order": items,
        "loadings": out,
        "factor_correlation": float(phi[0, 1]) if phi is not None else None,
    }


def main():
    cfa = build_cfa_fixture()
    promax = build_promax_fixture()
    with open(os.path.join(HERE, "fixture_cfa.json"), "w") as f:
        json.dump(cfa, f, indent=2)
    with open(os.path.join(HERE, "fixture_promax.json"), "w") as f:
        json.dump(promax, f, indent=2)
    with open(os.path.join(HERE, "expected_cfa.json"), "w") as f:
        json.dump(cfa_expected(cfa), f, indent=2)
    with open(os.path.join(HERE, "expected_promax.json"), "w") as f:
        json.dump(promax_expected(promax), f, indent=2)
    print("Wrote fixtures + expected values to", HERE)


if __name__ == "__main__":
    main()
