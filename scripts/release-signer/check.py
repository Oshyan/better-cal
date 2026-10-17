"""Check the prepared environment with stdlib only, before importing any signer dependency."""
import hashlib
import json
from pathlib import Path
import sys

mode, directory, config = sys.argv[1:]
base, config = Path(directory).resolve(), Path(config)
pin = (config / "python-version").read_text().strip()
if sys.version.split()[0] != pin or not Path(sys.base_prefix).resolve().is_relative_to(base / "python"):
    sys.exit("release-signer: wrong interpreter; prepare the pinned uv-managed Python")
files = {}
for path in sorted((base / "venv").rglob("*")):
    name = path.relative_to(base / "venv").as_posix()
    if path.is_symlink():
        files[name] = "link:" + str(path.readlink())
    elif path.is_file():
        with path.open("rb") as source:
            digest = hashlib.sha256()
            for chunk in iter(lambda: source.read(65536), b""):
                digest.update(chunk)
            files[name] = digest.hexdigest()
state = {"python": pin, "lock": hashlib.sha256((config / "requirements.txt").read_bytes()).hexdigest(), "files": files}
receipt = base / "prepared.json"
if mode == "prepare":
    with receipt.open("x") as out:
        json.dump(state, out, sort_keys=True)
elif mode != "check" or not receipt.is_file() or json.loads(receipt.read_text()) != state:
    sys.exit("release-signer: environment or lock changed; prepare a replacement environment explicitly")
