"""Agent Card signing across SDKs: a card signed by the official A2A Python
SDK verifies with the PHP SDK, and a card signed by the PHP SDK verifies with
the Python SDK (utils/signing.py). ES256 and HS256, plus tampered cards that
both sides must reject.

    PYTHONPATH=<deps> python3 tests/Interop/python/signing_interop.py

Needs a2a-sdk[signing] and php with this repo's vendor/ installed.
"""

import json
import pathlib
import shutil
import subprocess
import sys
import tempfile

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec
from google.protobuf.json_format import MessageToJson, Parse

from a2a.types import AgentCapabilities, AgentCard, AgentInterface, AgentSkill
from a2a.utils import signing

REPO = pathlib.Path(__file__).resolve().parents[3]
PHP_DIR = REPO / 'tests' / 'Interop' / 'php'
HMAC_KEY = b'interop-hmac-key-interop-hmac-key-0123456789'


def sample_card() -> AgentCard:
    return AgentCard(
        name='Interop Agent',
        description='Signed by one SDK, verified by the other. Unicode: café 日本',
        version='1.0.0',
        supported_interfaces=[
            AgentInterface(url='https://agent.example.com/a2a', protocol_binding='JSONRPC', protocol_version='1.0'),
        ],
        capabilities=AgentCapabilities(streaming=True, push_notifications=False),
        default_input_modes=['text/plain'],
        default_output_modes=['text/plain'],
        skills=[AgentSkill(id='s1', name='Skill', description='A skill', tags=['interop'])],
    )


def php(script: str, *args: str) -> subprocess.CompletedProcess:
    return subprocess.run(['php', str(PHP_DIR / script), *args], capture_output=True, text=True, check=False)


def main() -> int:
    tmp = pathlib.Path(tempfile.mkdtemp(prefix='a2a-signing-'))
    private = ec.generate_private_key(ec.SECP256R1())
    (tmp / 'ec-private.pem').write_bytes(private.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    (tmp / 'ec-public.pem').write_bytes(private.public_key().public_bytes(
        serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
    (tmp / 'hmac.key').write_bytes(HMAC_KEY)
    public_pem = (tmp / 'ec-public.pem').read_bytes()

    failures = 0

    def check(label: str, ok: bool, detail: str = '') -> None:
        nonlocal failures
        print(f"{'OK  ' if ok else 'FAIL'} {label}" + (f'  ({detail})' if detail and not ok else ''))
        failures += 0 if ok else 1

    for alg, sign_key, verify_key_file, py_verify_key in (
        ('ES256', private, 'ec-public.pem', public_pem),
        ('HS256', HMAC_KEY.decode(), 'hmac.key', HMAC_KEY.decode()),
    ):
        # 1. Python signs, PHP verifies.
        card = signing.create_agent_card_signer(sign_key, {'alg': alg, 'kid': 'py-key', 'jku': None, 'typ': 'JOSE'})(sample_card())
        signed = tmp / f'py-signed-{alg}.json'
        signed.write_text(MessageToJson(card))
        result = php('verify-card.php', str(signed), str(tmp / verify_key_file), alg)
        check(f'{alg}: Python-signed card verifies in PHP', result.returncode == 0, result.stdout + result.stderr)

        tampered = json.loads(signed.read_text())
        tampered['description'] = 'tampered'
        (tmp / 'tampered.json').write_text(json.dumps(tampered))
        result = php('verify-card.php', str(tmp / 'tampered.json'), str(tmp / verify_key_file), alg)
        check(f'{alg}: tampered Python-signed card is rejected by PHP', result.returncode == 1, result.stdout + result.stderr)

        # 2. PHP signs, Python verifies.
        unsigned = tmp / 'unsigned.json'
        unsigned.write_text(MessageToJson(sample_card()))
        key_file = 'ec-private.pem' if alg == 'ES256' else 'hmac.key'
        result = php('sign-card.php', str(unsigned), str(tmp / key_file), alg, 'php-key')
        if result.returncode != 0:
            check(f'{alg}: PHP signs the card', False, result.stdout + result.stderr)
            continue
        php_card = Parse(result.stdout, AgentCard())
        verifier = signing.create_signature_verifier(lambda kid, jku: py_verify_key, [alg])
        try:
            verifier(php_card)
            check(f'{alg}: PHP-signed card verifies in Python', True)
        except signing.SignatureVerificationError as e:
            check(f'{alg}: PHP-signed card verifies in Python', False, repr(e))

        php_card.description = 'tampered'
        try:
            verifier(php_card)
            check(f'{alg}: tampered PHP-signed card is rejected by Python', False, 'accepted')
        except signing.InvalidSignaturesError:
            check(f'{alg}: tampered PHP-signed card is rejected by Python', True)

    # 3. Both SDKs produce the same canonical bytes.
    unsigned = tmp / 'unsigned.json'
    unsigned.write_text(MessageToJson(sample_card()))
    py_canonical = signing._canonicalize_agent_card(sample_card())
    result = subprocess.run(
        ['php', '-r', 'require $argv[1]; echo A2A\\Utils\\Signing::canonicalizeAgentCard(A2A\\Client\\A2ACardResolver::parseAgentCard(json_decode(file_get_contents($argv[2]))));',
         str(REPO / 'vendor' / 'autoload.php'), str(unsigned)],
        capture_output=True, text=True, check=False,
    )
    check('Both SDKs produce identical canonical (JCS) bytes', result.stdout == py_canonical, f'php={result.stdout!r} py={py_canonical!r}')

    shutil.rmtree(tmp, ignore_errors=True)
    print(f"\n{'all signing interop checks passed' if failures == 0 else f'{failures} signing interop check(s) FAILED'}")
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
