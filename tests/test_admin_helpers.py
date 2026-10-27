"""Yönetici testleri için ortak yardımcılar (TOTP üretimi, CLI aracı)."""
import base64, hmac, hashlib, struct, subprocess, time, os
from lib import ROOT, TESTS


def totp(secret, t=None, off=0):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    step = int((t or time.time()) // 30) + off
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def cli(op, email, name='', pw='', pw2=None):
    env = dict(os.environ, DNL_CONFIG=os.path.join(TESTS, 'config.test.php'), ADM_OP=op, ADM_EMAIL=email, ADM_NAME=name, ADM_PW=pw, ADM_PW2=pw if pw2 is None else pw2)
    r = subprocess.run(['php', os.path.join(ROOT, 'site', '_app', 'yonetici.php')], env=env, capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr
