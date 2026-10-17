"""Exercise the stdlib-only environment gate using invented files, never a signing key."""
from pathlib import Path
import runpy
import sys
import tempfile
from unittest.mock import patch

checker = Path(__file__).resolve().parents[1] / "release-signer/check.py"
count = 0
with tempfile.TemporaryDirectory(prefix="bc-signer-test-") as temporary:
    base = Path(temporary)
    config = base / "config"
    config.mkdir()
    (config / "python-version").write_text("3.14.8\n")
    lock = config / "requirements.txt"
    lock.write_text("invented==1.0 --hash=sha256:fixture\n")
    (base / "venv/lib").mkdir(parents=True)
    library = base / "venv/lib/invented.py"
    library.write_text("# invented dependency\n")

    def invoke(mode="check", version="3.14.8", prefix=None, rejected=False):
        global count
        failed = False
        with patch.object(sys, "argv", [str(checker), mode, str(base), str(config)]), patch.object(sys, "version", version), patch.object(sys, "base_prefix", str(prefix or base / "python/pinned")):
            try:
                runpy.run_path(str(checker), run_name="__main__")
            except SystemExit:
                failed = True
        assert failed == rejected, (mode, version, prefix, rejected)
        count += 1

    invoke(rejected=True)  # No implicit preparation.
    invoke("prepare")
    invoke()
    invoke(version="3.14.9", rejected=True)
    invoke(prefix=base / "system-python", rejected=True)
    library.write_text("# substituted dependency\n")
    invoke(rejected=True)
    library.write_text("# invented dependency\n")
    extra = base / "venv/lib/injected.pth"
    extra.write_text("import invented\n")
    invoke(rejected=True)
    extra.unlink()
    lock.write_text("changed lock\n")
    invoke(rejected=True)
    lock.write_text("invented==1.0 --hash=sha256:fixture\n")
    invoke()
print(f"Signer environment security: {count} checks passed (no key accessed).")
