# Анализатор страниц

[![Actions Status](https://github.com/Brokgar/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/Brokgar/php-project-9/actions)

Веб-приложение для базового SEO-анализа сайтов. Оно сохраняет адреса в PostgreSQL и по запросу получает код ответа, содержимое `h1`, `title` и мета-описание страницы.

**Демо:** [php-project-9-nhxo.onrender.com](https://php-project-9-nhxo.onrender.com/)

## Возможности

- Добавление и нормализация URL (схема, домен и порт).
- Защита от пустых, некорректных и повторно добавленных адресов.
- Проверка доступности страницы и сохранение HTTP-статуса.
- Извлечение SEO-метаданных: `h1`, `title` и `meta[name="description"]`.
- Просмотр списка сайтов и истории всех проверок.

## Технологии

- PHP 8.1+
- Slim 4
- PostgreSQL
- Composer
- Bootstrap 5

## Требования

- PHP `^8.1` с расширениями `pdo` и `pdo_pgsql`
- Composer
- PostgreSQL
- Make (для команд из `Makefile`)

## Установка и запуск

```bash
git clone https://github.com/Brokgar/php-project-9.git
cd php-project-9
make setup
```

Создайте базу PostgreSQL и перед запуском задайте строку подключения в формате URL:

```bash
export DATABASE_URL='postgres://username:password@localhost:5432/page_analyzer'
```

Инициализируйте схему из единого файла `database.sql`:

```bash
make db-init
```

Запустите приложение:

```bash
make start
```

По умолчанию оно будет доступно по адресу [http://localhost:8000](http://localhost:8000). Чтобы использовать другой порт:

```bash
PORT=8080 make start
```

## Использование

1. Откройте главную страницу и добавьте адрес сайта, например `https://example.com`.
2. Перейдите на страницу добавленного сайта.
3. Нажмите «Запустить проверку», чтобы сохранить статус ответа и SEO-данные страницы.
4. Откройте раздел «Сайты», чтобы просмотреть все адреса и дату их последних проверок.

## Разработка

```bash
# Проверка стиля кода
make lint

# Запуск тестов
make test

# Проверка composer.json
make validate
```
