"""ReliCheck MM Studio statistical engine.

Phase 2 scope: the four classical tests plus stats_suggest_test, ported
from api/_stats.php on the web app. Output shape matches the PHP contract
exactly so the desktop app and the web app return identical results for
identical input.
"""

from .tests import chi_square, t_test, anova, pearson
from .suggest import suggest_test
from . import ingest

__all__ = ["chi_square", "t_test", "anova", "pearson", "suggest_test", "ingest"]
__version__ = "0.2.0"
