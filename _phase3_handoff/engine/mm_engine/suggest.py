"""Test routing logic, ported from stats_suggest_test() in api/_stats.php.

Given a (predictor, outcome) pair and their var_type strings plus group
counts, returns one of: 'chi_square', 't_test', 'anova', 'pearson', or
None when no classical test applies.
"""

from __future__ import annotations
from typing import Optional

_NUMERIC_TYPES = {"numeric", "ordinal", "frequency", "intensity", "sentiment_num"}
_CAT_TYPES = {"binary", "category", "sentiment"}


def suggest_test(
    pred_type: str,
    out_type: str,
    pred_groups: int,
    out_groups: int,
) -> Optional[str]:
    """Mirror of stats_suggest_test() — same inputs, same outputs."""
    pt = (pred_type or "").lower()
    ot = (out_type or "").lower()
    is_pred_num = pt in _NUMERIC_TYPES
    is_out_num = ot in _NUMERIC_TYPES
    is_pred_cat = pt in _CAT_TYPES
    is_out_cat = ot in _CAT_TYPES

    if is_pred_num and is_out_num:
        return "pearson"
    if is_pred_cat and is_out_cat:
        return "chi_square"
    if is_pred_num and is_out_cat:
        if out_groups == 2:
            return "t_test"
        if out_groups >= 3:
            return "anova"
        return None
    if is_pred_cat and is_out_num:
        if pred_groups == 2:
            return "t_test"
        if pred_groups >= 3:
            return "anova"
        return None
    return None
