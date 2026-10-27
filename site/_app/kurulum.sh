#!/bin/bash
# DNL Station kurulum: bash public_html/_app/kurulum.sh
cd "$(dirname "$0")" || exit 1
CFG="$(cd ../.. && pwd)/dnlstation-config.php"
if [ ! -f "$CFG" ]; then
  echo "Veritabani: dnlstati_dnlstation (Enter = bu, farkliysa adini yazin)"
  read -r -p "Veritabani adi: " DBN
  read -r -p "Kullanici adi (Enter = ayni): " DBU
  read -r -s -p "Veritabani sifresi (yazarken gorunmez): " DBP; echo
  export DBN DBU DBP
fi
php kurulum.php
unset DBP
