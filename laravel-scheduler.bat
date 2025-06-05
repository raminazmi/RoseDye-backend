@echo off
cd C:\Users\ramin\OneDrive\Desktop\أعمالي\مجلد جديد (2)\RoseDye\RoseDye-Backend
php artisan notifications:send-expiring >> storage/logs/scheduler.log 2>&1