# Зорге 9 — CMS-админка

Symfony 7 + EasyAdmin для редактирования текстов и картинок лендинга.

Живёт на **том же сервере**, что и сайт (178.253.42.235), в собственной БД
`cms_admin`. Не пересекается с Wolf CMS старого сайта.

## URL

```
https://zorge9.infoseledka.ru/cms-admin/login
```

Первый пользователь: **`admin`** / **`admin`** — обязательно сменить через
«Пользователи» → редактировать запись.

## Что можно редактировать

| Раздел | Что внутри |
|---|---|
| **Тексты лендинга** | Все `<h1>..<h6>` и `<p>` со всех 13 страниц. Очистите поле «Текст для отображения» — вернётся оригинал. |
| **Картинки лендинга** | Все `<picture>` и `<img>` со всех 13 страниц. Загрузите файл в «Медиа-библиотеке» и привяжите его — заменится сразу. Очистите привязку — вернётся оригинал. |
| **Новости / акции** | Свободная коллекция, открываются по `/news/{slug}` (Phase 2 — пока не подключено). |
| **Медиа-библиотека** | Загруженные файлы, доступны по `/cms-admin/uploads/media/...`. |
| **Настройки сайта** | Singleton-настройки (типа промо-полосы в шапке). |

## Как это работает

1. `cms-admin/bin/annotate_landing.py` проходит по `site/*.html` и
   проставляет `data-cms-img-key` / `data-cms-text-key` на каждый
   editable-элемент. Сейчас в `site/` ~13K таких ключей.
2. `app:seed-from-manifest` создаёт строку в `text_block` / `image_block`
   для каждого ключа с `default_value` / `default_src`. Поле `value` /
   `media` остаются NULL — пока админ их не заполнит.
3. nginx роутит лендинг-страницы через `/_cms-render.php`
   (см. `setup-db-and-nginx.sh`).
4. `cms-admin/public/_cms-render.php` читает HTML, тянет overrides
   из БД, заменяет содержимое тегов и отдаёт.

Wolf-страницы (`/office`, `/pent`, `/retail`, и т.д.) **не идут** через
render middleware — они продолжают рендериться Wolf'ом как раньше.

## Деплой и обновления

### Изменения в HTML (новый ключ, переаннотация)

```bash
# Локально:
python cms-admin/bin/annotate_landing.py --site-root site \
    --manifest cms-admin/bin/landing-manifest.json

# Закоммитить site/* + manifest; GitHub Actions сам зальёт.
# На сервере подтянуть новые ключи в БД:
ssh root@178.253.42.235 'cd /var/www/cms-admin && \
    APP_ENV=prod php bin/console app:seed-from-manifest bin/landing-manifest.json'
```

Команда идемпотентна — существующие ключи не трогает, добавляет только новые.

### Изменения в Symfony-коде (сущности, контроллеры, конфиг)

```bash
# Локально правишь cms-admin/src/... → scp на сервер → cache:clear.
# Существующего auto-deploy для cms-admin/ НЕТ — это деплоится вручную.
scp -i .deploy-secrets/deploy_key \
    cms-admin/src/Controller/Admin/XCrudController.php \
    root@178.253.42.235:/var/www/cms-admin/src/Controller/Admin/
ssh root@178.253.42.235 'cd /var/www/cms-admin && \
    APP_ENV=prod php bin/console cache:clear && \
    chown -R www-data:www-data var/'
```

### Сущности — миграции

```bash
ssh root@178.253.42.235 'cd /var/www/cms-admin && \
    APP_ENV=prod php bin/console doctrine:migrations:diff && \
    APP_ENV=prod php bin/console doctrine:migrations:migrate -n'
```

## Что осталось

- Pipeline `/news` / `/news/{slug}` (сейчас сущности есть, рендеринга нет)
- Промо-полоса (SiteSetting есть, рендер в `header.phtml` или вверху всех
  лендинг-страниц нужно сделать)
- `cms-admin/` авто-деплой через GitHub Actions (сейчас только `site/`)
- Сильный пароль для admin вместо `admin/admin` ⚠

## Файлы

```
cms-admin/
├── bin/annotate_landing.py   ← annotator (один прогон → 13K ключей)
├── config/packages/
│   ├── security.yaml         ← admin auth (form_login + CSRF)
│   ├── vich_uploader.yaml    ← media uploads → public/uploads/media/
│   └── csrf.yaml             ← stateful CSRF
├── public/
│   ├── _cms-render.php       ← landing render middleware (standalone)
│   └── index.php             ← Symfony front controller
├── src/
│   ├── Command/
│   │   ├── CreateAdminCommand.php       ← app:create-admin
│   │   └── SeedFromManifestCommand.php  ← app:seed-from-manifest
│   ├── Controller/Admin/    ← EasyAdmin CRUDs for each entity
│   ├── Entity/              ← User, TextBlock, ImageBlock, MediaItem, NewsItem, SiteSetting
│   └── Repository/          ← Repos + helper queries
├── templates/security/login.html.twig
└── migrations/Version*.php  ← initial schema
```

## Безопасность

- `admin/admin` — **немедленно сменить** (пользователи → admin → edit → новый пароль).
- БД `cms_admin` имеет своего пользователя `cms_admin` с рандомным паролем
  (хранится только в `/var/www/cms-admin/.env.local` на сервере, права 640).
- Render middleware ловит любую DB-ошибку и отдаёт исходный HTML —
  лендинг **никогда** не упадёт в 500 из-за CMS.
