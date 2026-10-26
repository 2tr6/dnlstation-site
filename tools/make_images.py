import sys, os
from PIL import Image, ImageDraw, ImageFilter, ImageFont

out = sys.argv[1]
img_dir = os.path.join(out, 'assets', 'img')
os.makedirs(img_dir, exist_ok=True)

ROWS = {0: [8], 1: [0,1,2,3,4,5], 2: [0,1,2,3,4,5,6,7], 3: [0,1,6,7,8], 4: [0,1,7,8], 5: [0,1,7,8],
        6: [0,1,7,8], 7: [0,1,7,8], 8: [0,1,7,8], 9: [0,1,6,7,8], 10: [0,1,2,3,4,5,6,7], 11: [0,1,2,3,4,5]}
BG = (8, 7, 14)

def lerp(a, b, k): return round(a + (b - a) * k)

def draw_d(im, x0, y0, cell, gap, glow=True, ghost=False):
    """Pixel D: 9 cols x 12 rows (row 0 = cyan dot)."""
    layer = Image.new('RGBA', im.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    r = max(1, cell // 4)
    for row in range(12):
        for col in range(9):
            on = col in ROWS[row]
            x = x0 + col * (cell + gap); y = y0 + row * (cell + gap)
            if on:
                if row == 0 and col == 8: c = (34, 211, 238, 255)
                else:
                    k = (row + col) / 19
                    c = (lerp(194, 91, k), lerp(179, 52, k), lerp(255, 234, k), 255)
                d.rounded_rectangle([x, y, x + cell, y + cell], radius=r, fill=c)
            elif ghost:
                d.rounded_rectangle([x, y, x + cell, y + cell], radius=r, fill=(255, 255, 255, 8))
    if glow:
        g = layer.filter(ImageFilter.GaussianBlur(cell * 0.8))
        im.alpha_composite(g); im.alpha_composite(g)
    im.alpha_composite(layer)

def radial(im, cx, cy, rad, color, alpha):
    layer = Image.new('RGBA', im.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).ellipse([cx - rad, cy - rad, cx + rad, cy + rad], fill=color + (alpha,))
    im.alpha_composite(layer.filter(ImageFilter.GaussianBlur(rad * 0.45)))

def icon(size, pad_ratio, rounded=True):
    im = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    bgl = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(bgl)
    if rounded: d.rounded_rectangle([0, 0, size - 1, size - 1], radius=int(size * 0.22), fill=BG + (255,))
    else: d.rectangle([0, 0, size, size], fill=BG + (255,))
    im.alpha_composite(bgl)
    radial(im, size * 0.6, size * 0.4, size * 0.45, (106, 69, 245), 90)
    inner = size * (1 - 2 * pad_ratio)
    # D grid height = 12 cells + 11 gaps; gap = cell*0.18
    cell = inner / (12 + 11 * 0.18)
    gap = cell * 0.18
    w = 9 * cell + 8 * gap; h = 12 * cell + 11 * gap
    draw_d(im, int((size - w) / 2), int((size - h) / 2), max(1, int(cell)), max(1, round(gap)), glow=size >= 120)
    if rounded:
        mask = Image.new('L', (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=int(size * 0.22), fill=255)
        im.putalpha(Image.composite(im.getchannel('A'), Image.new('L', (size, size), 0), mask))
    return im

icon(192, 0.14).save(os.path.join(img_dir, 'icon-192.png'), optimize=True)
icon(512, 0.14).save(os.path.join(img_dir, 'icon-512.png'), optimize=True)
icon(512, 0.24, rounded=False).save(os.path.join(img_dir, 'icon-maskable-512.png'), optimize=True)
icon(180, 0.14, rounded=False).convert('RGB').save(os.path.join(out, 'apple-touch-icon.png'), optimize=True)
ico = icon(256, 0.10)
ico.save(os.path.join(out, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])

# ---- Open Graph 1200x630 ----
W, H = 1200, 630
og = Image.new('RGBA', (W, H), BG + (255,))
radial(og, 930, 260, 420, (106, 69, 245), 120)
radial(og, 150, 560, 360, (34, 211, 238), 40)
dots = Image.new('RGBA', (W, H), (0, 0, 0, 0)); dd = ImageDraw.Draw(dots)
for y in range(12, H, 24):
    for x in range(12, W, 24):
        dd.ellipse([x - 1, y - 1, x + 1, y + 1], fill=(255, 255, 255, 14))
og.alpha_composite(dots)
draw_d(og, 820, 140, 28, 6, glow=True, ghost=True)

F = '/usr/share/fonts/opentype/inter/'
def font(name, size):
    for n in (name, 'Inter-Bold.otf'):
        p = os.path.join(F, n)
        if os.path.exists(p): return ImageFont.truetype(p, size)
    return ImageFont.load_default()
d = ImageDraw.Draw(og)
d.rounded_rectangle([80, 96, 80 + 360, 96 + 40], radius=8, fill=(106, 69, 245, 40), outline=(139, 108, 255, 110))
d.rounded_rectangle([92, 112, 100, 120], radius=2, fill=(34, 211, 238, 255))
d.text((112, 105), 'PlayStation ve oyun salonları için', font=font('Inter-Medium.otf', 19), fill=(214, 204, 255))
d.text((76, 170), 'DNL Station', font=font('InterDisplay-Bold.otf', 96), fill=(247, 245, 255))
d.text((80, 300), 'Masalar, süre, kafeterya ve kasa', font=font('InterDisplay-SemiBold.otf', 40), fill=(184, 166, 255))
d.text((80, 352), 'tek ekranda.', font=font('InterDisplay-SemiBold.otf', 40), fill=(103, 227, 245))
d.rounded_rectangle([80, 470, 80 + 300, 470 + 60], radius=10, fill=(106, 69, 245, 255))
d.text((96, 486), '14 gün ücretsiz deneyin', font=font('Inter-SemiBold.otf', 23), fill=(255, 255, 255))
d.text((404, 487), 'dnlstation.com.tr', font=font('Inter-Medium.otf', 22), fill=(166, 161, 191))
og.convert('RGB').save(os.path.join(img_dir, 'og-image.png'), optimize=True)
print('ok')
