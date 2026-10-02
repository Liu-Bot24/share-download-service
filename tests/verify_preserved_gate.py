#!/usr/bin/env python3
"""Keep the four independent 3ca48d5 sources intact; UI adaptations live in the runner."""
import hashlib
import pathlib

TESTS = pathlib.Path(__file__).resolve().parent
EXPECTED = {
    'merge_gate_recovery.php': '81c9bada55ec62b1004f8c202973b9377731178814a4148ec00f67fc73cde772',
    'merge_gate_migration.php': 'bf45230047ded4849ae329ba9606dbfd9bc7e088242297cf3b89887d4af5068b',
    'merge_gate_http.py': 'e5eb30d6a84279c1c1c4b88b595bef2c902c3796ddaca890d445e2a4a3ffff28',
    'merge_gate_browser.mjs': '2841578aef953b7755be35a0d53f5e276a09af84bcc8aae5d49a6e9d98c23f37',
}
for name, expected in EXPECTED.items():
    if hashlib.sha256((TESTS/name).read_bytes()).hexdigest() != expected:
        raise AssertionError('Independent source changed: '+name)
    print('PASS preserved 3ca48d5 source:', name)
print('4 preserved source checks passed; 0 failed')
