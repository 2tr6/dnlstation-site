#!/bin/bash
# DNL Station ana yonetici hesabi: bash public_html/_app/yonetici.sh
cd "$(dirname "$0")" || exit 1
echo "1) Yeni yonetici olustur"
echo "2) Yonetici sifresini sifirla"
echo "3) Authenticator (2 adimli dogrulama) sifirla"
read -r -p "Secim (1/2/3): " ADM_OP
read -r -p "E-posta: " ADM_EMAIL
ADM_NAME=""; ADM_PW=""; ADM_PW2=""
if [ "$ADM_OP" = "1" ]; then read -r -p "Ad soyad: " ADM_NAME; fi
if [ "$ADM_OP" = "1" ] || [ "$ADM_OP" = "2" ]; then
  echo "Sifre en az 12 karakter olmali (yazarken gorunmez)."
  read -r -s -p "Sifre: " ADM_PW; echo
  read -r -s -p "Sifre tekrar: " ADM_PW2; echo
fi
export ADM_OP ADM_EMAIL ADM_NAME ADM_PW ADM_PW2
php yonetici.php
unset ADM_PW ADM_PW2
