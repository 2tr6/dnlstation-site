"""Yerel test yardımcıları: PHP sunucusunu başlatır, API istemcisi sağlar."""
import os, re, glob, json, shutil, signal, subprocess, sys, time
import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TESTS = os.path.join(ROOT, 'tests')
TMP = os.path.join(TESTS, 'tmp')
MAILDIR = os.path.join(ROOT, 'site', '_app', 'storage', 'mail')
BASE = 'http://127.0.0.1:8790'
MIGRATE_TOKEN = 'test-migrate-token-0123456789abcdef0123456789'


class Server:
    def __init__(self, fresh=True):
        if fresh:
            shutil.rmtree(TMP, ignore_errors=True)
            shutil.rmtree(MAILDIR, ignore_errors=True)
        os.makedirs(TMP, exist_ok=True)
        env = dict(os.environ, DNL_CONFIG=os.path.join(TESTS, 'config.test.php'))
        self.p = subprocess.Popen(['php', '-S', '127.0.0.1:8790', '-t', os.path.join(ROOT, 'site'), os.path.join(TESTS, 'router.php')],
                                  env=env, stdout=open(os.path.join(TMP, 'server.log'), 'w'), stderr=subprocess.STDOUT)
        for _ in range(50):
            try:
                requests.get(BASE + '/api/system/health', timeout=1)
                break
            except Exception:
                time.sleep(0.1)

    def migrate(self):
        r = requests.post(BASE + '/api/system/migrate', headers={'X-Migrate-Token': MIGRATE_TOKEN})
        assert r.status_code == 200, r.text
        return r.json()

    def stop(self):
        self.p.send_signal(signal.SIGTERM)
        self.p.wait(timeout=5)


class Client:
    def __init__(self):
        self.s = requests.Session()
        self.csrf = None

    def refresh_csrf(self):
        r = self.s.get(BASE + '/api/auth/csrf')
        self.csrf = r.json()['data']['csrf']
        return self.csrf

    def get(self, path, **kw):
        return self.s.get(BASE + '/api' + path, **kw)

    def post(self, path, body=None, csrf=True, headers=None, **kw):
        h = {'Content-Type': 'application/json', 'Origin': BASE}
        if csrf:
            if self.csrf is None:
                self.refresh_csrf()
            h['X-CSRF-Token'] = self.csrf
        if headers:
            h.update(headers)
        return self.s.post(BASE + '/api' + path, data=json.dumps(body or {}), headers=h, **kw)

    def put(self, path, body=None):
        return self.s.put(BASE + '/api' + path, data=json.dumps(body or {}), headers={'Content-Type': 'application/json', 'Origin': BASE, 'X-CSRF-Token': self.csrf or self.refresh_csrf()})

    def delete(self, path):
        return self.s.delete(BASE + '/api' + path, headers={'Origin': BASE, 'X-CSRF-Token': self.csrf or self.refresh_csrf()})

    def me(self):
        r = self.get('/me')
        if r.status_code == 200:
            self.csrf = r.json()['data']['csrf']
        return r


def last_mail_link(kind):
    files = sorted(glob.glob(os.path.join(MAILDIR, '*.eml')))
    for f in reversed(files):
        txt = open(f, encoding='utf-8').read()
        m = re.search(r'/' + kind + r'/#t=([a-f0-9]{24}\.[A-Za-z0-9_-]{43})', txt)
        if m:
            return m.group(1)
    return None


RESULTS = []


def check(name, cond, detail=''):
    RESULTS.append((name, bool(cond), detail))
    print(('  OK  ' if cond else '  FAIL') + '  ' + name + ('' if cond else '  -> ' + str(detail)[:300]))
    return cond


def summary():
    fails = [r for r in RESULTS if not r[1]]
    print(f'\n{len(RESULTS) - len(fails)}/{len(RESULTS)} test geçti')
    return 1 if fails else 0


def register(c, email='sahip@arena.test', password='Guclu.Sifre-2026', name='Yağız Test', business='Arena Game Center'):
    return c.post('/auth/register', {
        'name': name, 'business': business, 'phone': '0532 123 45 67', 'email': email,
        'password': password, 'password_confirm': password, 'plan': 'pro', 'period': 'yillik',
        'types': ['playstation', 'bilardo'], 'accept': True})
