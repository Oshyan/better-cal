#!/usr/bin/env python3
"""Generate server/data/iata-airports.php from OurAirports airports.csv."""
import csv, math, re, subprocess, sys

TYPE_RANK = {"large_airport": 0, "medium_airport": 1, "small_airport": 2}

best = {}
with open(sys.argv[1], newline="", encoding="utf-8") as f:
    for row in csv.DictReader(f):
        code = row["iata_code"].strip().upper()
        if not re.fullmatch(r"[A-Z]{3}", code):
            continue
        if row["type"] == "closed":
            continue
        try:
            lat = round(float(row["latitude_deg"]), 5)
            lng = round(float(row["longitude_deg"]), 5)
        except ValueError:
            continue
        # The table is executable PHP: a NaN or infinity from a poisoned row
        # would be emitted as an invalid token and break every airport lookup
        # (security review BC-20). Only finite, in-range coordinates get in.
        if not (math.isfinite(lat) and math.isfinite(lng)):
            continue
        if not (-90 <= lat <= 90 and -180 <= lng <= 180):
            continue
        score = (0 if row["scheduled_service"] == "yes" else 1,
                 TYPE_RANK.get(row["type"], 3))
        entry = (score, lat, lng, row["name"].strip(),
                 row["municipality"].strip(), row["iso_country"].strip())
        if code not in best or score < best[code][0]:
            best[code] = entry

def esc(s):
    return s.replace("\\", "\\\\").replace("'", "\\'")

out = sys.argv[2]
with open(out, "w", encoding="utf-8") as f:
    f.write("<?php\n\n")
    f.write("// Generated from OurAirports airports.csv (public domain,\n")
    f.write("// https://ourairports.com/data/), " )
    f.write("rows with an IATA code, closed airports\n")
    f.write("// excluded, duplicates resolved by scheduled service then airport size.\n")
    f.write("// Format: code => [lat, lng, name, municipality, iso_country].\n")
    f.write("// Regenerate with tools/gen-iata.py (see repo).\n")
    f.write("return [\n")
    for code in sorted(best):
        _, lat, lng, name, muni, cc = best[code]
        f.write(f"'{code}'=>[{lat},{lng},'{esc(name)}','{esc(muni)}','{esc(cc)}'],\n")
    f.write("];\n")
# The output must parse before anyone commits it.
lint = subprocess.run(["php", "-l", out], capture_output=True, text=True)
if lint.returncode != 0:
    sys.stderr.write(lint.stdout + lint.stderr)
    sys.exit("generated table does not parse; not safe to commit")
print(f"{len(best)} airports -> {out}")
