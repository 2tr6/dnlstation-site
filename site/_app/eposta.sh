#!/bin/bash
# DNL Station e-posta kurulumu: bash public_html/_app/eposta.sh
cd "$(dirname "$0")" || exit 1
read -r -s -p "noreply@ e-posta hesabinin sifresi (gorunmez): " MAIL_PW; echo
read -r -p "Test e-postasi gonderilecek adres (sizin adresiniz): " MAIL_TO
export MAIL_PW MAIL_TO
php eposta.php
unset MAIL_PW
