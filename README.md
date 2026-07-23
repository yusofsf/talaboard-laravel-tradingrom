# Talaboard Laravel Trading Room

ربات و پنل اتاق معاملات متصل به `talaboard-laravel`.

## راه‌اندازی

1. در `.env` مقادیر `TALABOARD_API_URL`، `TALABOARD_API_TOKEN`، `TELEGRAM_BOT_TOKEN`، شماره کارت و شبا را وارد کنید.
2. `php artisan migrate`
3. `php artisan storage:link`
4. برای ثبت webhook، آدرس عمومی زیر را به تلگرام بدهید: `POST /api/telegram/webhook` و همان مقدار را در `TELEGRAM_WEBHOOK_SECRET` تنظیم کنید.

قرارداد پیش‌فرض API قیمت تلابورد: `GET /api/trading/prices` با خروجی `{"prices":[{"symbol":"gold_ounce","price":...}]}`. نمادهای قابل پشتیبانی: `gold_ounce`, `silver_ounce`, `gold_mesghal`, `gold_995_gram`, `gold_9999_gram`, `full_coin`, `half_coin`, `quarter_coin`.

قرارداد ثبت معامله: `POST /api/trading/trades`؛ شناسهٔ محلی، سمت، واحد، مقدار و قیمت در بدنه ارسال می‌شود. در صورت خطا معامله به وضعیت `submitted` می‌رود تا بعداً بررسی شود.

پنل وب قیمت‌ها و لیست‌های جداگانهٔ خرید/فروش را در `/` نشان می‌دهد. ادمینِ واردشده فیش‌ها را در `/admin/deposits` می‌بیند و تأیید از API `POST /api/admin/deposits/{id}/approve` انجام می‌شود.

## تأیید فیش در ربات

پس از فرستادن `/deposit مبلغ` و عکس فیش، ربات فایل تصویر را در هاست، در `storage/app/public/receipts/telegram` ذخیره می‌کند و برای همهٔ کاربرانی که `is_admin=1` و `telegram_chat_id` دارند ارسال می‌کند. ادمین با دکمهٔ «تأیید و شارژ کیف پول» همان فیش را تأیید می‌کند؛ موجودی فقط یک بار و در تراکنش دیتابیس شارژ می‌شود.

برای فعال‌سازی ادمین، او ابتدا باید یک‌بار به ربات `/start` بفرستد تا شناسهٔ چت ثبت شود، سپس مقدار `is_admin` او را در جدول `users` برابر `1` کنید. روی هاست نیز یک‌بار `php artisan storage:link` را اجرا کنید تا لینک مشاهدهٔ فیش‌ها در پنل وب کار کند.
