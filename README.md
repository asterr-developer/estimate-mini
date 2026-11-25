# Смета Mini App

Мини-приложение для Telegram с каталогом услуг, заявками и перепиской с поддержкой. Клиент выбирает услуги и отправляет заявку из мини-приложения. Подрядчик отвечает из раздела Telegram в кабинете [основной "Сметы"](https://github.com/asterr-developer/estimate) и может приложить ссылку на выпущенную смету.

[Смотреть запись работы мини-приложения и кабинета](docs/telegram-mini-app-demo.mp4) · [Скачать MP4](https://raw.githubusercontent.com/asterr-developer/estimate-mini/main/docs/telegram-mini-app-demo.mp4)

## Что работает

- Каталог берёт названия, цены и единицы измерения из справочника услуг подрядчика.
- Клиент может собрать заявку из нескольких услуг или задать вопрос поддержке.
- Сообщения сохраняются в переписке. Из кабинета можно ответить и прикрепить готовую смету.

Стек - PHP, Laravel, Vue 3, Vite, SQLite и Telegram Mini Apps SDK.

## Локальный запуск

Нужны PHP 8.5, Composer и Node.js 24. Для экспорта Excel в кабинете требуются расширения PHP GD и ZIP.

```powershell
composer install
npm ci
npm run build
Copy-Item .env.example .env
php artisan key:generate
New-Item database/database.sqlite -ItemType File
php artisan migrate
php -S 127.0.0.1:8094 -t public
```

Установите `TELEGRAM_LOCAL_DEMO=true` в `.env`. Создайте кабинет с именем `test` на [главной странице](http://127.0.0.1:8094/) и при необходимости загрузите примеры командой `php artisan db:seed --class=PortfolioExamplesSeeder`.

Откройте [мини-приложение](http://127.0.0.1:8094/mini), отправьте вопрос и ответьте на него во [входящих Telegram](http://127.0.0.1:8094/telegram-inbox). Обновите переписку на стороне клиента. Оба экрана в запущенном экземпляре используют одну базу, поэтому для проверки достаточно одного репозитория.

## Запуск внутри Telegram

Укажите `TELEGRAM_BOT_TOKEN`, `TELEGRAM_OWNER_ID` и публичный HTTPS-адрес `APP_URL` в `.env`. Выключите локальный режим, укажите адрес `/mini` в BotFather и запустите `php artisan queue:work` для отправки уведомлений. Локальный сценарий выше доступен без бота и хостинга.

```powershell
php artisan test
```
