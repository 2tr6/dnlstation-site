"""E-posta doğrulama kapısı: doğrulanmamış işletme sahibi panelin veri uçlarına giremez; doğrulayınca açılır."""
import sqlite3, os, sys
from lib import *

DB = os.path.join(TMP, 'test.db')
srv = Server(config='config.verify.test.php')
try:
    srv.migrate()
    o = Client(); register(o)
    r = o.get('/me')
    check('Doğrulanmamış sahip /me alabilir (hesap ekranı)', r.status_code == 200 and r.json()['data']['user']['verified'] is False, r.text[:150])
    r = o.get('/dashboard')
    check('Doğrulanmamış sahip panel verisine giremez', r.status_code == 403 and r.json()['error']['code'] == 'email_unverified', r.text[:200])
    o.refresh_csrf()
    r = o.post('/products', {'name': 'Su', 'price': '5'})
    check('Doğrulanmamış sahip yazamaz', r.status_code == 403 and r.json()['error']['code'] == 'email_unverified', r.text[:200])
    shutil.rmtree(MAILDIR, ignore_errors=True)  # yalnızca yeni gönderilen doğrulama maili okunsun
    o.refresh_csrf()
    r = o.post('/auth/resend-verification', {})
    check('Doğrulanmamış sahip doğrulama mailini tekrar isteyebilir', r.status_code == 200, r.text[:200])
    con = sqlite3.connect(DB)
    row = con.execute("SELECT u.email_verified_at FROM users u WHERE u.role='owner'").fetchone(); con.close()
    check('Doğrulama öncesi e-posta doğrulanmamış', row[0] is None)
    # Doğrulama bağlantısındaki token'ı veritabanındaki kayıttan değil, e-postadan okuyoruz
    token = last_mail_link('dogrula')
    check('Doğrulama bağlantısı e-postada var', token is not None)
    o.refresh_csrf()
    r = o.post('/auth/verify', {'token': token})
    check('Bağlantıya basınca doğrulanır', r.status_code == 200, r.text[:200])
    r = o.get('/dashboard')
    check('Doğrulanınca panel açılır', r.status_code == 200, r.text[:200])
finally:
    srv.stop()
sys.exit(summary())
