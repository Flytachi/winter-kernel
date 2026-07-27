# Actuator / Health — план (handoff, продолжить отсюда)

> Что хотим сделать с actuator в рамках редизайна `WinterApplication`. Часть решена,
> часть требует решения ПЕРЕД кодом. Контекст обсуждения: `doc/redes-check.md` §3.
> Health в новом `WinterApplication` ПОКА НЕ подключён — это следующий шаг.

---

## 0. Цель одной фразой

Дать диагностику приложения (`/actuator/*`: health/info/metrics/env/loggers/mappings)
так, чтобы она работала **и с web-сервером, и в headless** (приложение из только
Daemon+Schedule), и убрать хук `Health::configure()` с «бог-класса» → в атрибут.

---

## 1. Ключевое разделение (не путать)

- **Health-проверка (логика)** — транспорт-независима, работает всегда, в т.ч. без web.
- **`/actuator/*`** — это HTTP-**роуты** → без слушателя по HTTP их не прочитать.
- **НЕ путать** с Process/Daemon-статусом: `DaemonStatus`/`WorkerStatus` (`call daemon X
  status`) = process-level (жив ли воркер, рестарты). Actuator = app-level (БД, память,
  диск). Разные вещи, не сливать.

---

## 2. ✅ Решено — способ отдачи = ОБА

1. **`call health` (CLI)** — новая консольная команда. Зовёт индикатор, печатает JSON,
   код возврата 0/1. Работает всегда (headless тоже). Для k8s `exec`-проб / cron.
   ```yaml
   livenessProbe: { exec: { command: ["php","call","health"] } }
   ```
2. **`#[EnableActuator(port: 9000)]`** — отдельный management-компонент (крошечный
   сервер), поднимается **даже без web-компонента**. Калька Spring `management.server.port`.
   ```yaml
   livenessProbe: { httpGet: { path: /actuator/health, port: 9000 } }
   ```
3. **Если есть `Component::http()`** → `/actuator/*` на основном сервере (как сейчас).
4. **`#[EnableActuator(...)]` заменяет `Health::configure()`** — параметры (`port`,
   `middleware`, `indicator`) переезжают в атрибут на App-классе.

Пример:
```php
#[EnableActuator(port: 9000, middleware: InternalOnlyMiddleware::class)]
final class App extends WinterApplication { /* ... */ }
```

---

## 3. 🟡 РЕШИТЬ ПЕРЕД КОДОМ — форма индикатора

Текущая модель: **ОДИН** `HealthIndicator` с 6 методами (см. §5), кастом через
наследование + `Health::configure(indicator:)`. Это НЕ спринговский «много маленьких».

- **B1** — оставить один индикатор; только перенести конфиг в `#[EnableActuator]`.
  Минимум изменений. Кодер наследует весь индикатор и переопределяет метод.
- **B2 (тяготеем сюда)** — разбить на много маленьких `HealthContributor` (каждый
  чекает одно: БД, redis, диск); фреймворк сам находит их через `ImplementorCollector`
  и агрегирует в `/actuator/health`. Системные секции (info/metrics/env/loggers/
  mappings) остаются встроенными. Drop-in, как понравилось в §2 redes-check.
  ```php
  final class RedisHealth implements HealthContributor {
      public function check(): Health { return $redis->ping() ? Health::up() : Health::down(); }
  }
  ```
  Минус: переделка интерфейса Health + агрегатор.

> **Решение оставлено на завтра. Пользователь склоняется к B2.** Начать с этого выбора.

---

## 4. Куда встроить в WinterApplication (точки интеграции)

1. **Чтение атрибута** — в `WinterApplication::bootstrap()`, рядом с `applyImports()`:
   прочитать `#[EnableActuator]` на `static::class` → сохранить конфиг (port/middleware/
   indicator). (См. как сделан `applyImports()` — тот же приём с рефлексией атрибутов.)
2. **Web-путь** — если есть `Component::http()` и actuator включён → зарегистрировать
   `/actuator/*` роуты (эквивалент нынешнего `Health::configure`), чтобы `Router::fromScan`
   в `serveHttp()` их подхватил. Проверить, КАК сейчас `/actuator` попадает в роутер
   (`src/Http/Health/Health.php` + где Router читает `Health::getConfig()`).
3. **Management-порт** — если задан `port` → поднять отдельный маленький сервер только
   на `/actuator/*`. Варианты: отдельный `addProcess` в `serveHttp`, либо отдельный
   companion в headless. Дизайн уточнить (Swoole `Http\Server` на своём порту, только
   actuator-роуты).
4. **CLI** — новая команда `console/Command/Health.php`: boot → собрать секции индикатора
   (health/info/metrics/...) → `echo json` → `exit(up?0:1)`. Не зависит от web.
   Учесть: `WinterApplication::run()` уже отдаёт неизвестные глаголы в `Core`, значит
   `call health` дойдёт до команды после boot автоматически.

---

## 5. Текущий код Health (карта — что есть сейчас)

- `src/Http/Health/HealthIndicatorInterface.php` — интерфейс, **6 методов**:
  `health(): array`, `info(): array`, `metrics(): array`, `env(): array`,
  `loggers(): array`, `mappings(): array`.
- `src/Http/Health/HealthIndicator.php` — дефолтная реализация (класс implements
  interface); есть helper `dbHealth(string $rootDir)`, системные метрики.
- `src/Http/Health/Health.php` — статический реестр:
  `configure(indicator = HealthIndicator::class, middleware = null)` → пишет `self::$config`;
  `getConfig()`, `setMappings()/getMappings()`, `setRootDir()/getRootDir()`,
  статические хелперы (`cpu()` и др.). Регистрирует `/actuator/*` эндпоинты.
- Порог статуса: degraded ≥80% ресурсов, down ≥90% или отказ соединения.

---

## 6. Resume-чеклист (с чего начать завтра)

1. [ ] Выбрать **B1 или B2** (форма индикатора). Пользователь → скорее B2.
2. [ ] Если B2: спроектировать `HealthContributor` (интерфейс `check(): Health`) +
       агрегатор + `Health` value-объект (up/down/withDetail). Системные секции —
       оставить во встроенном индикаторе.
3. [ ] Атрибут `#[EnableActuator(port?, middleware?, indicator?)]` +
       `src/App/...` + чтение в `bootstrap()`.
4. [ ] Web-путь: регистрация `/actuator/*` при наличии `Component::http()`.
5. [ ] Management-порт: отдельный сервер при заданном `port` (в т.ч. headless).
6. [ ] CLI `console/Command/Health.php` (`call health`, JSON + exit-код).
7. [ ] Тесты в `tests/App` (агрегация contributors, exit-код CLI, attribute-config).
8. [ ] Обновить `doc/redes-check.md` §3 (🟡 → ✅) и `doc/winter-application-flow.md`.

> Не трогать `Boot`/`Application` (правило редизайна). `WinterApplication` —
> отдельный вход. Полный контекст редизайна: `doc/redes-check.md`,
> `doc/winter-application-flow.md`. Память: `winter-application-redesign`.
