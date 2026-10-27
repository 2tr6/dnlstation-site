"""Fiyat senkronu: sunucu fiyatları (Subscription) ile ana sayfa, üye ol ekranı ve yapılandırılmış veri aynı olmalı."""
import re, sys, json
from lib import ROOT, summary, check

SUB = open(f'{ROOT}/site/_app/src/Services/Subscription.php', encoding='utf-8').read()
IDX = open(f'{ROOT}/site/index.html', encoding='utf-8').read()
UYE = open(f'{ROOT}/site/uye-ol/index.html', encoding='utf-8').read()

plans = {}
for key, name, m, y in re.findall(r"'(\w+)' => \['name' => '([^']+)', 'monthly' => (\d+), 'yearly' => (\d+)", SUB):
    plans[key] = (int(m) // 100, int(y) // 100)
life = re.search(r"LIFETIME = \['name' => '[^']+', 'price' => (\d+)\]", SUB)
life_tl = int(life.group(1)) // 100 if life else None

print('== Sunucu fiyatları ==')
check('Üç paket tanımlı', {'baslangic', 'pro', 'salon'} <= set(plans), plans)
check('Ömür boyu fiyatı tanımlı', life_tl == 9990, life_tl)

print('== Ana sayfa ==')
for key, data_m, data_y in [('baslangic', 159, 1590), ('pro', 259, 2590), ('salon', 449, 4490)]:
    check(f'Ana sayfa {key} kartı sunucuyla aynı', f'data-m="{data_m}" data-y="{data_y}"' in IDX and plans[key] == (data_m, data_y), plans.get(key))
check('Ana sayfa ömür boyu kartı sunucuyla aynı', f'data-lifetime="{life_tl}"' in IDX and f'₺{life_tl:,}'.replace(',', '.') in IDX)
check('Ana sayfa JSON-LD sınırsız teklifi', f'"price": "{life_tl}"' in IDX)

print('== Üye ol ==')
for key, data_m, data_y in [('baslangic', 159, 1590), ('pro', 259, 2590), ('salon', 449, 4490)]:
    check(f'Üye ol {key} seçeneği sunucuyla aynı', f'value="{key}" data-m="{data_m}" data-y="{data_y}"' in UYE)
check('Üye ol sınırsız seçeneği sunucuyla aynı', f'value="sinirsiz" data-m="0" data-y="0" data-life="{life_tl}"' in UYE)
check('Üye ol sınırsız seçeneği kayda kabul ediliyor', 'sinirsiz' in open(f'{ROOT}/site/_app/src/Controllers/AuthController.php', encoding='utf-8').read())
sys.exit(summary())
