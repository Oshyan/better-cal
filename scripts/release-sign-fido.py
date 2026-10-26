#!/usr/bin/env python3
# Release signing with the everyday hardware security key (FIDO2), with an
# offline recovery key file (docs/releasing.md, Signing). Installs only
# verify. Needs the key plugged in, its FIDO2 PIN and a touch.
#
#   bash scripts/release-signer.sh enroll --key-id yubikey-2
#       make a signing credential on the key (stored on the key itself, so it
#       works from any computer) and print its entry for server/config/release-keys.php
#   bash scripts/release-signer.sh sign --statement-file FILE --out SIG [--key-id yubikey-1]
#       sign a release statement, adding to SIG (created if missing); the
#       credential names its key, so --key-id only insists on a particular one
#   bash scripts/release-signer.sh show
#       print the plugged-in key's entry for release-keys.php again
#
# The key signs its authenticator data followed by SHA-256 of the statement;
# server/src/Support/ReleaseSignature.php checks exactly that.

import argparse
import base64
import getpass
import hashlib
import json
import os
import sys

from fido2.ctap2 import Ctap2, CredentialManagement
from fido2.ctap2.pin import ClientPin
from fido2.hid import CtapHidDevice

RP_ID = "release-signing.better-cal"
EDDSA = -8


def device() -> Ctap2:
    devices = list(CtapHidDevice.list_devices())
    if not devices:
        sys.exit("No security key found. Plug it in and try again.")
    if len(devices) > 1:
        sys.exit("More than one security key is plugged in; leave just the one.")
    return Ctap2(devices[0])


def ask_pin() -> str:
    # Asked before connecting: macOS drops a connection left idle while
    # someone takes their time at the prompt.
    return getpass.getpass("Security key PIN: ")


def token(ctap: Ctap2, pin: str, permission) -> tuple[ClientPin, bytes]:
    client_pin = ClientPin(ctap)
    return client_pin, client_pin.get_pin_token(pin, permission, RP_ID)


def enroll(args) -> None:
    pin = ask_pin()
    ctap = device()
    if EDDSA not in [a.get("alg") for a in (ctap.info.algorithms or [])]:
        sys.exit("This key can't make Ed25519 credentials.")
    client_pin, pin_token = token(ctap, pin, ClientPin.PERMISSION.MAKE_CREDENTIAL)
    cdh = os.urandom(32)
    print("Touch the key...")
    att = ctap.make_credential(
        cdh,
        {"id": RP_ID, "name": "Better-Cal release signing"},
        {"id": args.key_id.encode(), "name": args.key_id, "displayName": "Better-Cal release signing"},
        [{"type": "public-key", "alg": EDDSA}],
        options={"rk": True},
        pin_uv_param=client_pin.protocol.authenticate(pin_token, cdh),
        pin_uv_protocol=client_pin.protocol.VERSION,
    )
    print("\nThe credential is stored on the key. Add this to server/config/release-keys.php:\n")
    print(entry_line(args.key_id, att.auth_data.credential_data.public_key))


def show(args) -> None:
    # The entry enroll printed, read back from the credential on the key.
    pin = ask_pin()
    ctap = device()
    client_pin, pin_token = token(ctap, pin, ClientPin.PERMISSION.CREDENTIAL_MGMT)
    manager = CredentialManagement(ctap, client_pin.protocol, pin_token)
    creds = manager.enumerate_creds(hashlib.sha256(RP_ID.encode()).digest())
    if not creds:
        sys.exit("This key has no Better-Cal signing credential; enroll it first.")
    print("\nThe entry for server/config/release-keys.php:\n")
    for cred in creds:
        user = cred[CredentialManagement.RESULT.USER]
        print(entry_line(user["id"].decode(), cred[CredentialManagement.RESULT.PUBLIC_KEY]))


def entry_line(key_id: str, key) -> str:
    x = key[-2]
    if key[3] != EDDSA or len(x) != 32:
        sys.exit("The key returned an unexpected key type.")
    public = base64.b64encode(x).decode()
    return f"    ['id' => '{key_id}', 'type' => 'fido2-ed25519', 'rpId' => '{RP_ID}', 'publicKey' => '{public}'],"


def sign(args) -> None:
    statement = open(args.statement_file, "rb").read()
    if not statement.startswith(b"Better-Cal release signature v1\n"):
        sys.exit("That isn't a release statement.")
    pin = ask_pin()
    ctap = device()
    client_pin, pin_token = token(ctap, pin, ClientPin.PERMISSION.GET_ASSERTION)
    cdh = hashlib.sha256(statement).digest()
    print("Touch the key...")
    res = ctap.get_assertion(
        RP_ID,
        cdh,
        options={"up": True},
        pin_uv_param=client_pin.protocol.authenticate(pin_token, cdh),
        pin_uv_protocol=client_pin.protocol.VERSION,
    )
    # Enroll stored the key's id as the credential's user id, so whichever
    # enrolled key is plugged in signs as itself.
    found = (res.user or {}).get("id")
    key_id = found.decode() if isinstance(found, bytes) else args.key_id
    if not key_id:
        sys.exit("This key's credential doesn't say which key it is; pass --key-id.")
    if args.key_id and key_id != args.key_id:
        sys.exit(f"That's {key_id}, not {args.key_id}.")
    entry = {
        "key": key_id,
        "type": "fido2",
        "authData": base64.b64encode(bytes(res.auth_data)).decode(),
        "sig": base64.b64encode(res.signature).decode(),
    }
    if os.path.exists(args.out):
        data = json.load(open(args.out))
        if data.get("statement") != statement.decode():
            sys.exit(f"{args.out} signs a different statement.")
    else:
        data = {"statement": statement.decode(), "signatures": []}
    data["signatures"] = [s for s in data["signatures"] if s.get("key") != key_id] + [entry]
    with open(args.out, "w") as f:
        json.dump(data, f, indent=4)
        f.write("\n")
    print(f"Signed with {key_id}.")


def main() -> None:
    p = argparse.ArgumentParser(description="Better-Cal release signing with a security key")
    sub = p.add_subparsers(dest="cmd", required=True)
    e = sub.add_parser("enroll")
    e.add_argument("--key-id", required=True)
    s = sub.add_parser("sign")
    s.add_argument("--statement-file", required=True)
    s.add_argument("--out", required=True)
    s.add_argument("--key-id")
    sub.add_parser("show")
    args = p.parse_args()
    {"enroll": enroll, "sign": sign, "show": show}[args.cmd](args)


if __name__ == "__main__":
    try:
        main()
    except OSError:
        sys.exit("Couldn't talk to the security key (another app, like a browser, may be using it). "
                 "Close anything waiting on it, unplug it and plug it back in, then try again.")
    except Exception as e:  # CTAP errors: wrong PIN, PIN blocked, no credential
        sys.exit(f"The security key said no: {e}")
