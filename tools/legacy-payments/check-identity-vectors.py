#!/usr/bin/env python3
"""Check frozen fictional economic vectors independently; no application/network I/O."""
import hashlib
import json
from pathlib import Path


def canonical(value):
    return (json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
            .replace("\u2028", "\\u2028").replace("\u2029", "\\u2029"))


def check():
    path = Path(__file__).resolve().parents[2] / "tests/Fixtures/LegacyReceipts/identity-vectors.json"
    vectors = json.loads(path.read_text(encoding="utf-8"))
    if len(vectors) < 2:
        raise ValueError("ASCII and Unicode vectors are required")
    for vector in vectors:
        for field, encoded, digest, domain in [
            ("identity", "identity_bytes", "effect_key", b"legacy-economic-effect-v1\0"),
            ("economic", "economic_bytes", "economic_sha256", b"legacy-economic-facts-v1\0"),
        ]:
            actual = canonical(vector[field])
            if actual != vector[encoded]:
                raise ValueError(f"{vector['name']}: canonical {field} bytes differ")
            if hashlib.sha256(domain + actual.encode("utf-8")).hexdigest() != vector[digest]:
                raise ValueError(f"{vector['name']}: frozen {field} hash differs")
    print(f"Verified {len(vectors)} fictional identity/economic vectors")


if __name__ == "__main__":
    check()
