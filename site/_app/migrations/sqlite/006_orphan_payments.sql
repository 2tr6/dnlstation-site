-- DNL Station — silinmiş işletmelerden kalan yetim tahsilat kayıtlarını temizle
DELETE FROM admin_payments WHERE business_id NOT IN (SELECT id FROM businesses);
