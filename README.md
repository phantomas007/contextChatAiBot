# Contextbot

AI-бот для Telegram: слушает групповые чаты, генерирует саммари через Ollama и публикует дайджесты в группу сам бот @ContextChatAiBot
группа поддержки t.me/phantomaspopuas 


## Как это работает

```
Telegram → webhook → очередь `incoming_messages` → MessageSaveHandler
               ↓
        SummaryGenerationHandler (Ollama)  ←  app:dispatch-summary-jobs (каждые 3 мин)
               ↓ создаёт кирпич (Context)
        CheckAggregationGroupHandler       ←  app:check-aggregation-group (каждые 3 мин, сдвиг +2)
               ↓ сохраняет AggregatedGroupContext (sentAt=NULL)
        PublishGroupJobHandler             ←  app:publish-group-contexts (каждую минуту)
               ↓ отправляет в Telegram, ставит sentAt
           [Группа получает дайджест]

Суточный путь:
        GenerateDailyAggregationGroupHandler  ←  app:generate-daily-aggregation-group (23:50)
               ↓ сохраняет AggregatedGroupContext (type=daily, sentAt=NULL)
        PublishGroupJobHandler             ←  app:publish-group-contexts (каждую минуту)
               ↓ отправляет в Telegram, ставит sentAt
```

**Кирпич** = саммари на каждые 50 сообщений (`SummaryBrickSize::MESSAGES_PER_BRICK`).
`GroupSettings.count_threshold` задаёт, сколько **сообщений чата** покрывает один count-based дайджест; число кирпичей = порог / 50 (в панели админа: 50, 100, 150, 200, 300 — см. `GroupAdminPanelService::COUNT_OPTIONS`).

## Стек


| Сервис   | Образ                 | Назначение      |
| -------- | --------------------- | --------------- |
| nginx    | nginx:1.27-alpine     | Reverse proxy   |
| php      | php:8.3-fpm-alpine    | Webhook         |
| worker   | php:8.3-fpm-alpine    | Очереди + cron  |
| db       | postgres:17-alpine    | PostgreSQL      |
| rabbitmq | rabbitmq:3-management | AMQP            |
| ollama   | ollama/ollama         | LLM для саммари |


## Развёртывание

### Где хранятся настройки

Все настройки — в **одном месте**, корневом `.env`. Никаких секретов в `app/` дублировать не нужно.


| Переменная                                      | Файл                  | Кто заполняет                          |
| ----------------------------------------------- | --------------------- | -------------------------------------- |
| `POSTGRES_`*, `RABBITMQ_*`                      | корневой `.env`       | вы                                     |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET` | корневой `.env`       | вы                                     |
| `TELEGRAM_BOT_LINK`, `ADMIN_TELEGRAM_USER_ID`   | корневой `.env`       | вы                                     |
| `OLLAMA_MODEL`, `OLLAMA_URL`                    | корневой `.env`       | вы                                     |
| `DATABASE_URL`                                  | `app/.env.local`      | создаётся автоматически при `make run` |
| `APP_SECRET` *(только prod)*                    | `app/.env.prod.local` | вы, только для prod                    |


docker-compose читает корневой `.env` и прокидывает переменные в контейнеры — Symfony использует их с наивысшим приоритетом.

---

### Локальная разработка

#### 1. Клонировать и настроить `.env`

```bash
git clone <repo-url>
cd context
cp .env.example .env
```

Открыть `.env` и заполнить реальными значениями:

```dotenv
POSTGRES_PASSWORD=надёжный_пароль
RABBITMQ_PASSWORD=надёжный_пароль

TELEGRAM_BOT_TOKEN=токен_от_BotFather        # получить у @BotFather
TELEGRAM_WEBHOOK_SECRET=любая_случайная_строка
TELEGRAM_BOT_LINK=https://t.me/имя_бота
ADMIN_TELEGRAM_USER_ID=ваш_telegram_id       # узнать у @userinfobot

OLLAMA_MODEL=OxW/Saiga_YandexGPT_8B:q4_K_M  # или другая модель из списка ниже
```

`app/.env.local` создаётся **автоматически** при первом `make run` — редактировать его не нужно.

#### 2. Запустить

Одна и та же логика в **`Makefile`**. На Windows **`make.cmd` / `make.ps1`** вызывают `make` через Git Bash — команды совпадают с Linux (при необходимости: `make run ENV=prod`).

Требования на Windows: [Git for Windows](https://git-scm.com/download/win) и **GNU Make** в PATH внутри Git Bash (часто: `choco install make`).

```bash
make run              # Linux/macOS / Git Bash
.\make run            # Windows (CMD или PowerShell → тот же Makefile)
```

`make run` собирает образы, поднимает контейнеры, устанавливает зависимости, применяет миграции, очищает кеш, запускает линтеры.

#### 3. Установить модель Ollama

```bash
docker compose exec ollama ollama pull OxW/Saiga_YandexGPT_8B:q4_K_M
```

Смена модели: поменять `OLLAMA_MODEL` в `.env` → `make run`.

#### 4. Зарегистрировать webhook (через ngrok)

Telegram требует публичный HTTPS-адрес. Для локальной разработки — **ngrok**:

```bash
# Установка (Windows)
winget install ngrok

# Добавить authtoken — один раз, после регистрации на ngrok.com
ngrok config add-authtoken ВАШ_ТОКЕН

# Запустить туннель (nginx слушает порт 80)
ngrok http 80
```

ngrok выдаст URL вида `https://xxxx.ngrok-free.app`. Зарегистрировать его как webhook:

```bash
# Linux/macOS
make console CMD="app:set-webhook https://xxxx.ngrok-free.app/api/telegram/webhook"

# Windows
.\make console -CMD "app:set-webhook https://xxxx.ngrok-free.app/api/telegram/webhook"
```

Один раз (или после смены списка команд) зарегистрировать **меню у кнопки «/»** в Telegram — см. раздел [Telegram: меню и панель админа](#telegram-меню-и-панель-админа).

**Нет команды `make` в Git Bash (Windows):** установите GNU Make, например `choco install make`, и перезапустите терминал.

**Ошибка парсера при `.\make` (Windows):** если PowerShell ругается на кириллицу в `make.ps1` — сохраните файл как UTF-8 with BOM в редакторе или запустите команды из **Git Bash**: `make run`.

> URL ngrok меняется при каждом перезапуске — нужно заново запускать `app:set-webhook`.
> Трафик от Telegram: `http://localhost:4040`

---

### Production

#### 1. Клонировать и настроить `.env`

```bash
git clone <repo-url>
cd context
cp .env.example .env
```

Заполнить `.env` так же, как для локальной разработки (см. выше), но с надёжными паролями.

#### 2. Создать `app/.env.prod.local`

Нужен только для prod, **не попадает в git**:

```bash
cp app/.env.prod.local.example app/.env.prod.local
```

Сгенерировать и вписать реальный `APP_SECRET`:

```bash
php -r "echo bin2hex(random_bytes(16));"
```

```dotenv
# app/.env.prod.local
APP_SECRET=сгенерированная_строка_32_символа
```

`DATABASE_URL` в этом файле уже собирается из переменных корневого `.env` — менять не нужно.

#### 3. Запустить в prod-режиме

```bash
make run ENV=prod          # Linux/macOS
.\make run -ENV prod        # Windows
```

В prod-режиме: `composer install --no-dev --optimize-autoloader`, прогрев кеша, линтеры пропускаются.

#### 4. Зарегистрировать webhook

```bash
# Linux/macOS
make console CMD="app:set-webhook https://yourdomain.com/api/telegram/webhook" ENV=prod

# Windows
.\make console -CMD "app:set-webhook https://yourdomain.com/api/telegram/webhook" -ENV prod
```

Повторять при смене домена или `TELEGRAM_WEBHOOK_SECRET`. Проверить текущий webhook:

```bash
curl https://api.telegram.org/bot<TOKEN>/getWebhookInfo
```

Меню команд у «/» в prod: `make console CMD="app:telegram-set-commands" ENV=prod` или `.\make console -CMD "app:telegram-set-commands" -ENV prod` (подробности — [ниже](#telegram-меню-и-панель-админа)).

---

## Telegram: меню и панель админа

### @BotFather

- **Group Privacy** → **Disable** (бот должен получать **все** сообщения в группах). Иначе в webhook приходят в основном команды и служебные события, а обычный текст чата — нет; запись в `telegram_groups` при добавлении бота при этом может появиться.
- Токен бота — секрет: не коммитьте `.env`, не вставляйте токен в скриншоты и логи; при утечке — `/revoke` в BotFather и новый токен в `TELEGRAM_BOT_TOKEN`.

### Меню у кнопки «/» (`setMyCommands`)

Команда регистрирует три набора (через Bot API):


| Scope                     | Кому видно                | Команды (как в меню «/»)                         |
| ------------------------- | ------------------------- | ------------------------------------------------ |
| `all_private_chats`       | личка с ботом             | `/start`, `/help`, `/my_chats`, `/ask_ai`        |
| `all_group_chats`         | любой участник группы     | `/ask_ai`                                        |
| `all_chat_administrators` | админы группы/супергруппы | `/group_settings`                                |

Настройки публикации для админа — только через **inline-кнопки** под сообщением после `/group_settings` (текстовых команд `/set_batch` и т.п. в коде нет).

Тексты описаний задаются в `app:telegram-set-commands` (`SetTelegramBotCommandsCommand`); после смены выполните команду снова. Команда **`/my_chats`** в меню «/» видна **только в личке с ботом**, не в группах. **`/ask_ai`** в меню «/» есть и в личке, и в группах (ограничения и лимиты — в следующем подразделе про группу). Если вручную набрать в личке, например, `/group_settings`, бот ответит, что команду нужно отправить в группе (`PrivateChatHandler`).

Запуск (нужен интернет до `api.telegram.org`):

```bash
make console CMD="app:telegram-set-commands"
# Windows
.\make console -CMD "app:telegram-set-commands"
```

Проверить, **что реально записано у Telegram** (если в клиенте «ничего не изменилось»):

```bash
make console CMD="app:telegram-dump-commands"
# Windows
.\make console -CMD "app:telegram-dump-commands"
```

Команда `app:telegram-set-commands` дублирует списки с `**language_code` `ru` и `en**`: у части клиентов с русским интерфейсом меню не обновляется без явной локали.

Если дамп показывает полный список, а в Telegram по-прежнему одна команда — **полностью закройте приложение Telegram** (не сворачивайте), откройте снова; на телефоне иногда помогает «Завершить приложение» в настройках.

Если команда падает с `**Idle timeout`** — сеть до Telegram медленная или нестабильная; повторите позже или попробуйте другую сеть/VPN. В коде для этого запроса задан увеличенный таймаут.

Перед регистрацией вызывается `**deleteMyCommands**` — сбрасывается старый список (в том числе если команды задавались вручную в BotFather), затем записываются три scope.

**Где что видно в клиенте Telegram**

- **Список у кнопки «/»** — только текстовые команды. В **личке** Telegram не подставляет автоматически команды из scope «группа» / «админы»: полный рабочий набор смотрите **внутри группы** у «/». Это не «цветные кнопки».
- **Панель с эмодзи** — появляется **в группе** **под сообщением бота** после `**/group_settings`** (только у администраторов чата). В личке с ботом этих кнопок не будет.

После успешного `app:telegram-set-commands` иногда нужно **перезапустить приложение Telegram** или открыть чат заново, чтобы список у «/» обновился.

### Панель администратора (группа)

Администратор группы пишет `**/group_settings`**. Бот отправляет сообщение со сводкой (порог по сообщениям, суточный обзор) и **inline-клавиатуру** с эмодзи:

- **📊 50 … 300** — порог публикации по числу сообщений (допустимые N: 50, 100, 150, 200, 300);
- **🚫 Выкл по числу** — отключить дайджест по порогу;
- **✅ Сутки вкл** / **⏹️ Сутки выкл** — суточный обзор вкл/выкл;
- **✕ Скрыть кнопки** — убирает inline-клавиатуру у сообщения панели (текст остаётся).

Не-админ при нажатии получает предупреждение. Текст сводки в **старом** сообщении панели не обновляется автоматически — актуальные строки сверху снова покажет **повторный** `/group_settings`.

Реализация: `app/src/Telegram/GroupAdminPanelService.php`, обработка нажатий — префикс `ga_*` в `CallbackQueryHandler`.

### Как сообщения попадают в БД

1. Telegram шлёт апдейт на `**POST /api/telegram/webhook`** (`TelegramWebhookController`).
2. Для текста в группе/супергруппе в очередь RabbitMQ `**incoming_messages**` уходит `IncomingTelegramUpdateMessage`.
3. Контейнер `**worker**` обрабатывает очередь в `**MessageSaveHandler**` — вставка в таблицу `messages`.

Если группа в БД есть, а строк в `messages` нет — проверьте: **worker** запущен, очередь `incoming_messages` не копится, **Group Privacy** выключен, в сообщении есть именно **текст** (`text`); вложения только с подписью в `caption` без отдельного текста **не** сохраняются (ограничение текущего обработчика).

---

## Как проверить Ollama


| Что проверить                 | Команда                                  |
| ----------------------------- | ---------------------------------------- |
| Модель в конфиге приложения   | `OLLAMA_MODEL` в корневом `.env`         |
| Список установленных моделей  | `docker compose exec ollama ollama list` |
| Подключение + тестовый запрос | `make console CMD="app:test-ollama"`     |


---

## Команды make


| Команда                     | Описание                                                 |
| --------------------------- | -------------------------------------------------------- |
| `make run`                  | Старт: инфра → composer → migrate → cache → worker       |
| `make run ENV=prod`         | То же в prod-режиме (no-dev, cache warmup, без линтеров) |
| `make build`                | Собрать образы (нужен интернет)                          |
| `make stop`                 | Остановить все контейнеры                                |
| `make worker-restart`       | Перезапустить воркеры и crond без остановки всего стека  |
| `make migrate`              | Применить миграции                                       |
| `make cache-clear`          | Очистить кеш Symfony и Redis                             |
| `make redis-clear`          | Только Redis (FLUSHDB)                                   |
| `make composer-install`     | Только `composer install` (как в `run`)                  |
| `make console CMD="..."`    | Консольная команда Symfony                               |
| `make lint` / `make cs-fix` | Линтеры                                                  |


**Telegram** (из корня репозитория; в Windows замените на `.\make console -CMD "…"`):


| Команда                                                                     | Назначение                                                                                   |
| --------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `make console CMD="app:set-webhook https://ВАШ_ДОМЕН/api/telegram/webhook"` | Установить URL webhook (после смены ngrok/домена или секрета)                                |
| `make console CMD="app:telegram-set-commands"`                              | Зарегистрировать меню у кнопки «/» в клиенте Telegram (`setMyCommands`)                      |
| `make console CMD="app:telegram-dump-commands"`                             | Вывести списки команд, как их возвращает `getMyCommands` (проверка, если меню не обновилось) |


Подробности: раздел [Telegram: меню и панель админа](#telegram-меню-и-панель-админа). В **prod** к тем же вызовам добавьте `ENV=prod` или `.\make … -ENV prod`.

`**make worker-restart`** — удобно когда поменял код без полного `make run`:

```bash
# Linux/macOS
make worker-restart

# Windows
.\make worker-restart
```

Перезапускает все `messenger:consume` воркеры и `crond` через `supervisorctl restart all`.
Очереди в RabbitMQ при этом не затрагиваются — сообщения не теряются.

---

## Очереди и воркеры


| Очередь                    | Назначение                                                           | Воркеры | Memory |
| -------------------------- | -------------------------------------------------------------------- | ------- | ------ |
| `incoming_messages`        | Сохранение входящих сообщений                                        | 5       | 128M   |
| `summary_jobs`             | Генерация кирпичей через Ollama                                      | 3       | 256M   |
| `aggregation_checks`       | Count-based агрегация                                                | 3       | 256M   |
| `daily_aggregation_checks` | Суточная агрегация (отдельный транспорт, не вытесняется count-based) | 3       | 256M   |
| `publish_group_jobs`       | Публикация дайджестов в Telegram                                     | 3       | 128M   |


Статус воркеров:

```bash
docker compose exec worker supervisorctl -c /etc/supervisor/supervisord.conf status
```

---

## Расписание (cron)


| Команда                                | Когда                             | Описание                                                                     |
| -------------------------------------- | --------------------------------- | ---------------------------------------------------------------------------- |
| `app:dispatch-summary-jobs`            | каждые 3 мин (`*/3`)              | Диспатчит `SummaryJobMessage` при накоплении ≥50 несуммаризованных сообщений |
| `app:check-aggregation-group`          | каждые 3 мин, сдвиг +2 (`2-59/3`) | Диспатчит `CheckAggregationMessage` для count-based групп                    |
| `app:publish-group-contexts`           | каждую минуту (`* * * * *`)       | Диспатчит `PublishGroupJobMessage` для неопубликованных агрегаций            |
| `app:generate-daily-aggregation-group` | 23:50 ежедневно                   | Диспатчит `GenerateDailyAggregationMessage` для всех групп                   |
| `app:collect-daily-stats`              | 00:05 ежедневно                   | Сбор суточной статистики (данные за вчера)                                   |
| `app:send-ai-private-reengage`         | 12:00 UTC ежедневно               | Напоминание в ЛС при долгой неактивности ask_ai (шаблоны в БД, см. `.env`)   |


**Сдвиг +2 минуты** между `dispatch-summary-jobs` и `check-aggregation-group` даёт кирпичам время попасть в БД до проверки порога агрегации.

---

## Команды бота

Актуальный набор для меню «/» задаётся `**app:telegram-set-commands`** (см. [Telegram: меню и панель админа](#telegram-меню-и-панель-админа)). Ниже — смысл команд в коде.

### Личка с ботом


| Команда     | В меню Telegram (описание)     | Поведение                                                                 |
| ----------- | ------------------------------ | ------------------------------------------------------------------------- |
| `/start`    | Старт бота                     | Приветствие, inline-кнопки «Мои чаты» / «Помощь»                         |
| `/help`     | Инструкция и список команд     | Справка                                                                   |
| `/my_chats` | Мои чаты                       | Список активных групп с ботом и подписки (то же, что кнопка «📬 Мои чаты») |
| `/ask_ai`   | Ask DeepSeek AI — Спросить ИИ | Вопрос к ИИ (DeepSeek); не более **3 запросов в сутки (UTC) на пользователя**; ответ приходит отдельным сообщением. Запросы не пишутся в таблицу `messages`. **В личке** можно набрать вопрос и **без** `/ask_ai` — любое сообщение, которое **не** начинается с `/`, обрабатывается как вопрос к ИИ |

### Группа / супергруппа — любой участник (`/ask_ai`)

Поведение **как в личке**: **`/ask_ai` с текстом** — один запрос к ИИ; **`/ask_ai` без текста** — та же подсказка, что и в ЛС («напиши вопрос после команды…»); **любое сообщение без `/`** трактуется как вопрос к ИИ (аналогично личке). Доступно **любому участнику**, не только админам.

**Важно:** обычный текст в группе **не** попадает в таблицу `messages` (очередь `incoming_messages` для этого апдейта не ставится) — он уходит только в пайплайн ИИ. Если в группе нужен и обычный сбор переписки, и отдельные вопросы боту, имеет смысл завести отдельный чат под бота или не добавлять бота в «боевой» чат с полной историей.

Лимит: **не более 3 запросов к ИИ в сутки (UTC) на чат** (общий лимит на группу). Реализация: `AskAiTelegramService`, `AiRequestRateLimiter`, очереди DeepSeek.

### Группа / супергруппа — администраторы чата


| Команда           | В меню Telegram «/» (админ)               | Поведение                                                                      |
| ----------------- | ----------------------------------------- | ------------------------------------------------------------------------------ |
| `/group_settings` | Настройки контекста (обычно единственная в меню) | Текст + **inline-кнопки** — все переключения только через них ([панель](#панель-администратора-группа)) |

Сущность `UserGroupSubscription` используется в пайплайне (например агрегация); команда `/subscribe` в боте пока не обрабатывается.

---

## Тестирование пайплайна

### Linux / macOS

```bash
# 1. Симулировать чат: отправить N сообщений на webhook (по умолчанию 3000)
make console CMD="app:test:simulate-chat"
# или с параметрами:
make console CMD="app:test:simulate-chat --count=100 --chat-id=-1001234567890"

# 2. Генерация кирпичей (диспатч при накоплении ≥50 сообщений)
make console CMD="app:dispatch-summary-jobs"

# 3. Проверка count-based агрегации
make console CMD="app:check-aggregation-group"

# 4. Публикация готовых агрегаций в группы
make console CMD="app:publish-group-contexts"

# 5. Суточный дайджест (агрегация кирпичей за день)
make console CMD="app:generate-daily-aggregation-group"

# 6. Сбор суточной статистики (данные за вчера)
make console CMD="app:collect-daily-stats"

# 7. Тест Ollama (подключение + модель)
make console CMD="app:test-ollama"
```

### Windows

```powershell
# 1. Симулировать чат
.\make console -CMD "app:test:simulate-chat"
# или с параметрами:
.\make console -CMD "app:test:simulate-chat --count=100 --chat-id=-1001234567890"

# 2. Генерация кирпичей
.\make console -CMD "app:dispatch-summary-jobs"

# 3. Проверка count-based агрегации
.\make console -CMD "app:check-aggregation-group"

# 4. Публикация готовых агрегаций в группы
.\make console -CMD "app:publish-group-contexts"

# 5. Суточный дайджест
.\make console -CMD "app:generate-daily-aggregation-group"

# 6. Сбор суточной статистики (данные за вчера)
.\make console -CMD "app:collect-daily-stats"

# 7. Тест Ollama
.\make console -CMD "app:test-ollama"
```

---

## Устранение неполадок

**Сборка (DNS/TLS):** стабильный интернет, DNS 8.8.8.8 в Docker, или `make run -ForceBuild`.

**Telegram — группа есть в БД, сообщений в `messages` нет:** включён **Group Privacy** у бота в @BotFather (нужно **Disable**); не запущен **worker** или копится очередь `**incoming_messages`** в RabbitMQ; webhook отвечает **401** (несовпадение `TELEGRAM_WEBHOOK_SECRET` с `secret_token` при `app:set-webhook`); сменился URL ngrok без повторного `app:set-webhook`; в апдейте нет поля `**text`** (например, только фото с подписью — см. `MessageSaveHandler`).

**Telegram — `app:telegram-set-commands` падает по таймауту:** см. [Telegram: меню и панель админа](#telegram-меню-и-панель-админа) (подраздел про `setMyCommands` и таймаут).

**Telegram — после `app:telegram-set-commands` в клиенте не видно новых команд:** выполните `app:telegram-dump-commands` и сравните с меню «/»; при полном дампе — сброс кэша клиента (полный выход из Telegram), проверьте, что открыт **тот же** бот, чей токен в `.env`.

**Кирпичи не создаются:** `make console CMD="app:test-ollama"`, логи `docker compose logs worker -f`, `messenger:failed:show`.

**Агрегация не публикуется:** проверить `aggregated_contexts_for_group` — есть ли записи с `sent_at IS NULL`; статус воркеров `publish_group_jobs`.

**Runpod / count-based агрегация «не шлёт запросы»:**

1. **PowerShell:** команда должна реально выполниться. Используйте `.\make console -CMD "app:check-aggregation-group"` или `.\make console CMD="app:check-aggregation-group"` (оба варианта поддерживаются в `make.ps1`). Строка вида `CMD="..."` без `-CMD` раньше давала ошибку «позиционный параметр» — теперь хвост `CMD=...` подхватывается автоматически.
2. **Очередь:** `app:check-aggregation-group` только **кладёт** задачи в RabbitMQ (`aggregation_checks`). Запрос к Runpod идёт, когда **воркер** обрабатывает сообщение. Нужен запущенный контейнер `worker` и процесс `messenger:consume` для `aggregation_checks` (см. supervisor).
3. **Условия вызова LLM:** Runpod endpoint агрегации (`RUNPOD_ENDPOINT_ID_AGGREGATION`) вызывается из `AggregationService::buildAndPersist` только если:
  - у группы в настройках задан **count-based порог** (`/set_batch 100` и т.д.);
  - накопилось **достаточно кирпичей**: для порога N нужно `N / 50` кирпичей (например, порог 200 → 4 кирпича). Один кирпич ≈50 сообщений = один `Context` после саммари;
  - кирпичи ещё не вошли в предыдущую агрегацию этого типа (курсор по `period_to`).
4. **Суточный дайджест (DAILY):** это другая очередь (`daily_aggregation_checks`) и команда `app:generate-daily-aggregation-group`; Runpod — `RUNPOD_ENDPOINT_ID_DAILY`.

---

## RabbitMQ

Веб-интерфейс управления очередями: **[http://localhost:15672](http://localhost:15672)**


| Параметр | Значение                                                  |
| -------- | --------------------------------------------------------- |
| URL      | [http://localhost:15672](http://localhost:15672)          |
| Логин    | из `.env`: `RABBITMQ_USER` (по умолчанию `rabbitmq_user`) |
| Пароль   | из `.env`: `RABBITMQ_PASSWORD`                            |
| VHost    | `/` (из `RABBITMQ_VHOST`)                                 |


В разделе **Queues** — очереди `incoming_messages`, `summary_jobs`, `aggregation_checks`, `daily_aggregation_checks`, `publish_group_jobs`.

---

## Сравнение моделей (бенчмарки MERA, ruMMLU)

Данные из [MERA](https://mera.a-ai.ru), [T-Bank Habr](https://habr.com/ru/companies/tbank/articles/865582/). Чем выше — тем лучше.


| Модель                                                          | MERA   | ruMMLU | Размер     | Контекст |
| --------------------------------------------------------------- | ------ | ------ | ---------- | -------- |
| **T-Lite-it-2.1** `second_constantine/t-lite-it-2.1`            | 0.55+  | 0.66+  | **5.0 GB** | 40K      |
| **T-Lite** `blackened/t-lite`                                   | 0.552  | 0.664  | ~5 GB      | 8K       |
| **T-Pro** `fomenks/T-Pro-1.0-it-q4_k_m`                         | 0.629  | 0.768  | ~20 GB     | —        |
| **Saiga YandexGPT 8B** `OxW/Saiga_YandexGPT_8B:q4_K_M`          | ~0.50* | ~0.62* | 4.9 GB     | —        |
| **YandexGPT-5-Lite** `yandex/YandexGPT-5-Lite-8B-instruct-GGUF` | ~0.50* | ~0.62* | 4.9 GB     | —        |
| **Qwen2.5-7B** `qwen2.5:7b`                                     | 0.482  | 0.610  | 4.7 GB     | —        |
| **Llama 3 8B** `llama3:latest`                                  | ~0.41  | ~0.51  | 4.7 GB     | —        |
| **Llama 3.2 3B** `llama3.2:3b`                                  | ~0.38  | ~0.48  | 2.0 GB     | —        |


 YandexGPT и Saiga — оценки (нет в MERA).

**Рекомендация для Contextbot:** `second_constantine/t-lite-it-2.1` (5 GB) — instruct-версия T-Lite, улучшенный instruction following. Альтернатива: Saiga (проверена в проекте).

```bash
docker compose exec ollama ollama pull second_constantine/t-lite-it-2.1
# В .env: OLLAMA_MODEL=second_constantine/t-lite-it-2.1
```

---

## Полезные команды

```bash
# Список моделей в Ollama
docker compose exec ollama ollama list

# Статус воркеров Supervisor
docker compose exec worker supervisorctl -c /etc/supervisor/supervisord.conf status

# Сброс БД
make db-reset

# Провалившиеся сообщения Messenger
make console CMD="messenger:failed:show"
make console CMD="messenger:failed:retry"
```

**Telegram (из корня репозитория, через `make console`):**

```bash
# Webhook (подставьте свой HTTPS URL)
make console CMD="app:set-webhook https://example.com/api/telegram/webhook"

# Меню команд у «/» в клиенте Telegram
make console CMD="app:telegram-set-commands"

# Что вернёт getMyCommands (диагностика меню)
make console CMD="app:telegram-dump-commands"
```

Проверка webhook у Telegram: `curl https://api.telegram.org/bot<TOKEN>/getWebhookInfo`

Запуск в режиме прод, пример команды
make console CMD="app:collect-daily-stats" ENV=prod


лог команд на сервере 
docker compose logs worker -f

docker compose -p context logs nginx -f
