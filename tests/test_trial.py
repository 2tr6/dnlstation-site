"""Deneme hatırlatmaları: 2 gün kala ve süre dolunca bir kez gönderilir, tekrar çalıştırmada çoğaltılmaz."""
import sqlite3, os, sys, subprocess, datetime
from lib import *

DB = os.path.join(TMP, 'test.db')
PHP = ['php', os.path.join(ROOT, 'site', '_app', 'hatirlatma.php')]
ENV = dict(os.environ, DNL_CONFIG=os.path.join(TESTS, 'config.test.php'))

def run_cron():
    p = subprocess.run(PHP, env=ENV, capture_output=True, text=True, timeout=60, stdin=subprocess.DEVNULL)
    return p.returncode, p.stdout.strip()

def at(delta_days):
    return (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=delta_days)).strftime('%Y-%m-%d %H:%M:%S')

def setq(q, a=()):
    con = sqlite3.connect(DB); con.execute(q, a); con.commit(); con.close()

def kinds():
    con = sqlite3.connect(DB)
    rows = con.execute("SELECT kind, status FROM email_log WHERE kind LIKE 'trial_%'").fetchall(); con.close()
    return rows

srv = Server()
try:
    srv.migrate()
    o = Client(); register(o); o.me()
    setq("UPDATE users SET email_verified_at = ? WHERE role = 'owner'", (at(0),))
    print('== 2 gün kala ==')
    setq("UPDATE businesses SET trial_ends_at = ?, paid_until = NULL", (at(1),))
    code, out = run_cron()
    check('Betik hatasız çalışır', code == 0, out)
    check('2 gün kala hatırlatma gönderilir', 'kala 1' in out and ('trial_soon', 'sent') in kinds(), out)
    code, out = run_cron()
    check('Tekrar çalıştırmada çoğaltılmaz', 'kala 0' in out and kinds().count(('trial_soon', 'sent')) == 1, out)

    print('== Süre doldu ==')
    setq("UPDATE businesses SET trial_ends_at = ?", (at(-1),))
    code, out = run_cron()
    check('Süre dolunca bilgilendirme gönderilir', 'bitti 1' in out and ('trial_expired', 'sent') in kinds(), out)
    code, out = run_cron()
    check('Bitiş bilgisi bir kez gönderilir', 'bitti 0' in out and kinds().count(('trial_expired', 'sent')) == 1, out)

    print('== Atlananlar ==')
    setq("UPDATE businesses SET trial_ends_at = ?, paid_until = ?", (at(1), at(20)))
    code, out = run_cron()
    check('Ücretli işletmeye hatırlatma gitmez', 'kala 0' in out, out)
    setq("UPDATE businesses SET paid_until = NULL, lifetime = 1")
    code, out = run_cron()
    check('Ömür boyu işletmeye hatırlatma gitmez', 'kala 0' in out, out)
    setq("UPDATE businesses SET lifetime = 0, trial_ends_at = ?", (at(1),))
    setq("UPDATE users SET email_verified_at = NULL WHERE role = 'owner'")
    code, out = run_cron()
    check('Doğrulanmamış sahibe hatırlatma gitmez', 'kala 0' in out, out)
finally:
    srv.stop()
sys.exit(summary())
