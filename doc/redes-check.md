# Redesign speak — живой лог решений (WinterApplication)

> Рабочий журнал обсуждения редизайна загрузчика. Сюда фиксируем **согласованные**
> куски по мере разбора. Статусы: ✅ принято · 🟡 обсуждается · ⬜ ещё не трогали.
>
> Общая цель: убрать «бог-класс» `Boot` (где один класс держит `components()`,
> `configure()`, `providers()`, `channels()`, `httpCors()`, `health()`, `plugins()`,
> `swooleConfig()`) → перейти к Spring-модели: **тонкий entry-класс +
> конфигурация, разъехавшаяся по классам, которые находит сканер.**

---

## ✅ 1. Beans — `#[Configuration]` + `#[Bean]` (замена `providers()`)

**Принято.** Нравится, потенциально закрывает много кейсов конфигурации.

### Суть
`#[Bean]` — это **сахар над существующим контейнером**, не отдельная система.
Коллектор под капотом дёргает ровно `$c->singleton()/->transient()/->request()`.

### Как работает
- Класс с `#[Configuration]` — контейнер бинов.
- Метод с `#[Bean]` — фабрика. **Ключ бина = тип возврата метода.**
- Тело метода = та же фабрика-замыкание, что раньше писали в `providers()`.
- Аргументы `#[Bean]`-метода автоинжектятся (в т.ч. `#[Value('ENV_KEY')]` из `.env`).

### Scope (важно!)
`#[Bean]` по умолчанию = **singleton** (метод вызывается 1 раз, объект кэшируется и
переиспользуется везде) — как `@Bean` в Spring.

| Запись | Под капотом |
|---|---|
| `#[Bean]` | `$c->singleton(ReturnType, factory)` — 1 объект на процесс |
| `#[Bean(scope: Scope::Transient)]` | `$c->transient(...)` — новый каждый раз |
| `#[Bean(scope: Scope::Request)]` | `$c->request(...)` — один на запрос/корутину |

> Асимметрия, держать в голове: `#[Bean]` по умолчанию singleton, а обычный
> сканируемый класс **без атрибута** — transient. (Тоже как в Spring.)

### Пример (в нашем стиле — `#[Autowired]`, без конструктора)

```php
#[Configuration]
final class AppConfig
{
    #[Bean]                                       // = $c->singleton(CacheInterface::class, ...)
    public function cache(): CacheInterface
    {
        return new RedisCache(env('REDIS_URL'));
    }

    #[Bean]                                       // аргументы автоинжектятся
    public function mailer(#[Value('MAIL_HOST')] string $host): MailerInterface
    {
        return new SmtpMailer($host);
    }
}
```

Потребитель — как обычно:

```php
class ReportService extends Service
{
    #[Autowired]
    private CacheInterface $cache;                // придёт бин из cache(), тот же объект везде
}
```

### Про `#[Service]` — НЕ добавляем
- У нас `Service` — это **базовый класс** (`Stereotype\Service`), его наследуют;
  зависимости через `#[Autowired]`-свойства. Атрибута `#[Service]` нет и не нужен.
- `extends Service` сам по себе в DI ничего не регистрирует — за scope отвечает
  атрибут `#[Singleton]/#[Request]/#[Transient]` (см. `DICollector`). Без атрибута
  класс автоварится по рефлексии как transient.
- Spring `@Service` = просто спец-`@Component` (метка + семантика). **Прироста
  скорости нет** — атрибут vs базовый класс в рантайме равны. Единственный
  реальный плюс атрибута — освобождает слот наследования PHP; нам сейчас не жмёт.
- Итог: простые сервисы — `extends Service` + `#[Autowired]` (+ `#[Singleton]` при
  нужде кэша). Сложную сборку (интерфейс→реализация, фабрика, скаляр из env) берёт
  `#[Bean]`.

---

## ✅ 2. Конфигураторы — интерфейс + авто-discovery (на примере CORS `WebConfigurer`)

**Принято.** Ключевой вывод: **этим паттерном можно добавлять сколько угодно
конфигов** — универсальный «drop-in» механизм.

### Суть
Кодер пишет класс, `implements` нужный интерфейс — и **приложение само его
находит** на скане. Никакой регистрации, никаких ссылок из App-класса.

### Как «оно само находит»
Механизм не новый — у нас **уже есть** `ImplementorCollector`
(`src/Collector/ImplementorCollector.php`): на скане собирает все не-абстрактные
классы, реализующие заданный интерфейс (так сейчас находятся `DbConfigInterface`).

```php
// framework side — в тот же скан, что уже идёт в boot():
$web = new ImplementorCollector(WebConfigurer::class);

Scanner::run(rootDir: Kernel::$pathRoot, cache: ...)
    ->collect(new DICollector($c))
    ->collect($async)
    ->collect($web)                          // ← одна строка = «ищи реализации WebConfigurer»
    ->execute();

// после скана — вызвать найденное:
$registry = new CorsRegistry();
foreach ($web->getResult() as $ref) {
    $configurer = $c->make($ref->getName());  // через контейнер → #[Autowired] внутри тоже работает
    $configurer->configureCors($registry);
}
Cors::configure(...$registry->build());       // применяем в существующий Cors::configure()
```

### Что делает кодер — только создать файл

```php
namespace Main\Config;

use Flytachi\Winter\K2\Http\Cors\WebConfigurer;
use Flytachi\Winter\K2\Http\Cors\CorsRegistry;

final class WebConfig implements WebConfigurer   // ← достаточно `implements`
{
    public function configureCors(CorsRegistry $cors): void
    {
        $cors->allowedOrigins('https://app.example.com')
             ->allowCredentials(true);
    }
}
```

Положил класс → сканер увидел `implements` → фреймворк вызвал. Удалил → дефолт.

### Нюансы
- Конфигураторов может быть **несколько** — вызовутся все (обычно хватает одного).
- Создаётся через `$c->make(...)` → внутри можно `#[Autowired]`-зависимости.

### Обобщение
Тот же паттерн (`implements интерфейс` → сканер находит → фреймворк вызывает)
переиспользуется для всех «конфигураторов» редизайна:

| Интерфейс | Заменяет | Что делает |
|---|---|---|
| `WebConfigurer` | `httpCors()` | настраивает CORS |
| `LoggingConfigurer` | `channels()` | регистрирует лог-каналы |
| `ServerConfigurer` | `swooleConfig()` | тюнит Swoole-сервер |
| `HealthIndicator` | `health()` | тот же discovery, но **собирается в список** проверок, а не «настраивает» |

---

## 🟡 3. Health / Actuator — способ отдачи РЕШЁН, индикатор ПЕРЕПИШЕМ

**Способ отдачи — принято (оба). Форма индикатора — будем переписывать, обсудим
отдельно** (не фиксируем реализацию сейчас).

### Важное разделение (зафиксировать в голове)
- **Health-проверка (логика)** — транспорт-независима, работает и в headless
  (например, приложение из только Daemon + Schedule).
- **`/actuator/*`** — это HTTP-**роуты** (`Health::configure()` регистрирует
  мэппинги, читает `Router`) → без слушателя по HTTP их прочитать некому.

### ✅ Способ отдачи health — ОБА
- **`call health` (CLI)** — команда зовёт индикатор, печатает JSON, exit 0/1.
  Работает всегда, в т.ч. headless. Для k8s `exec`-проб / cron.
- **`#[EnableActuator(port: 9000)]`** — отдельный management-компонент (крошечный
  сервер), поднимается **даже без `#[EnableWeb]`** (калька Spring
  `management.server.port`).
- Если есть `#[EnableWeb]` → `/actuator/*` на основном сервере, как сейчас.
- `#[EnableActuator(...)]` заменяет `Health::configure()` — параметры (`port`,
  `middleware`, `indicator`) переезжают в атрибут на App-классе.

### 🟡 Форма индикатора — ПЕРЕПИШЕМ (обсудить позже)
Текущая модель: **один** `HealthIndicator` с 6 методами (`health/info/metrics/env/
loggers/mappings`), кастом через наследование + `Health::configure(indicator:)`.
Это НЕ спринговский «много маленьких проверок». Обсудили направления:
- **B1** — оставить один индикатор (минимум изменений).
- **B2** — разбить на маленькие `HealthContributor` (drop-in, фреймворк сам находит
  через `ImplementorCollector` и агрегирует; системные секции остаются встроенными).

> Решение: **скорее всего перепишем** (тяготеем к B2 / drop-in), но детали —
> отдельным обсуждением. Пока НЕ реализуем.

### Не путать с Process/Daemon-статусом
`DaemonStatus`/`WorkerStatus` (через store, `call daemon <X> status`) — это
**process-level** liveness (жив ли воркер, рестарты). Health/Actuator — **app-level**
(БД, память, диск). Разные вещи, не сливаем.

---

## ✅ 4. Import + Starter (замена `plugins()`) — переименовано

**Принято.** `plugins()` → `import`. Два уровня, как в Java (не путать):

### A. `#[Import(...)]` — явная форма (делаем сразу)
Переименованный `Plugin::registry()` в атрибут. Ты контролируешь prefix / `required`.

```php
#[Import('acme/auth-plugin', '/auth')]
#[Import('acme/billing', '/billing', required: false)]
final class App extends WinterApplication { /* ... */ }
```

Под капотом — существующий механизм (`Composer\InstalledVersions::getInstallPath`,
скан `src/` пакета). Просто хук `plugins()` → атрибуты на App.

### B. True starter — авто-конфиг (фича поверх, позже)
Пакет **сам объявляет себя** winter-стартером через `composer.json`, ядро сканит
установленные пакеты и подключает **без строк в App** (= Spring Boot starter:
`composer require` → включилось):

```jsonc
// composer.json пакета acme/billing-starter
"extra": {
    "winter": {
        "starter": true,
        "prefix":  "/billing",
        "config":  "Acme\\Billing\\BillingConfiguration"  // его #[Configuration]
    }
}
```

- Новый механизм: читать `extra.winter` из `composer.json` установленных пакетов
  (composer-идиома вместо спринговского `AutoConfiguration.imports`).
- `#[Import('acme/billing')]` остаётся как **override** авто-старта (сменить prefix,
  отключить, `required:false`).
- **Порядок:** `#[Import]` сейчас, starter-autoconfig — отдельной фичей.

---

## ✅ 5. Web / Server — `ServerConfigurer` УБИРАЕМ, сливаем в web

**Принято.** Тюнинг сервера — это web-tier concern, отдельная сущность не нужна.
`swooleConfig()` уходит. Два уровня:

> ⚠️ ПОПРАВКА (после разбора): `#[EnableWeb]` **отменён** — такой аннотации в Java
> нет (web в Spring включается наличием зависимости, не аннотацией). Ручки сервера
> переезжают на существующий `Component::http(host, port)` + `.env` + `WebConfigurer`.
> См. §6 «Компоненты».

### Частые ручки — на `Component::http()` + `.env`

```php
Component::http(host: '0.0.0.0', port: 8000)   // host/port — как сейчас
// SERVER_WORKERS=8, SERVER_MAX_REQUEST=5000   — в .env
```

### Глубокий тюнинг — метод в `WebConfigurer` (тот же класс, что CORS)
Чтобы не заставлять реализовывать оба метода — **абстрактный адаптер с пустыми
дефолтами** (= спринговский `WebMvcConfigurerAdapter`): наследуешь, переопределяешь
только нужное.

```php
final class WebConfig extends WebConfigurerAdapter   // пустые дефолты configureCors + configureServer
{
    public function configureServer(ServerSettings $s): void   // override только это
    {
        $s->set('ssl_cert_file', '/etc/ssl/app.pem');
    }
}
```

> Итог: `ServerConfigurer` из §2-таблицы **удалён**. Сервер = часть web-поверхности:
> `#[EnableWeb]` (ручки) + `WebConfigurer::configureServer()` (редкий тюнинг).

---

## ✅ 6. Компоненты (что запускается) — оставляем метод `components()`

**Принято.** Никаких `#[EnableWeb]` (в Java такой аннотации нет — web включается
зависимостью, не аннотацией). Явный сигнал «из чего собрано приложение» = метод.

```php
protected static function components(): array
{
    return [
        Component::http(port: 8000),          // есть #[EnableWeb]? — НЕТ, только это
        Component::process(KernelSys::class),
        Component::daemon(Emails::class),
        Component::scheduler(),
    ];
}
```

- Честно, гибко (можно `if (env(...))`), без фейковых аннотаций.
- Параметры web-сервера: `Component::http(host, port)` + `.env` (`SERVER_*`) +
  `WebConfigurer::configureServer()` (редкий тюнинг). См. §5.
- `#[Import]` (§4) остаётся отдельно — `@Import` это **реальная** Spring-аннотация.
- Реальные Java-тоглы (`@EnableScheduling`/`@EnableAsync`) НЕ вводим — scheduler это
  просто `Component::scheduler()`, один механизм.

---

## ✅ 7. Логи (каналы) — как есть, норм

**Принято, не усложняем.** Базовые каналы (`http`, `sys`) + кастомные через `.env`
(`LOG_{NAME}_*`). Редкий код (динамические каналы) — интерфейс `LoggingConfigurer`
(тот же discovery-паттерн, что §2). Ничего не переделываем.

---

## ✅ 8. Аргументы `main(array $args)` — минимальные, без `--profile`

**Принято.** Только реальные ручки: `--port`, `--host` и т.п. Спринговские профили
(`--profile`) НЕ нужны — выкинули. Приоритет: **CLI-аргумент > .env > дефолт**.

---

## ✅ 9. Точка входа + Boot order — РЕАЛИЗОВАНО

**Принято и написано кодом.** `Boot`/`Application` НЕ тронуты — `WinterApplication`
это параллельный самостоятельный вход.

- `App::main($argv)` → `WinterApplication::run($argv)`.
- **Диспетчеризация:** `call run`/`run dev` → поднять приложение (`serve`); голый
  `call` → help, любой другой глагол (`make/daemon/cfg/...`) → консольный `Core`.
  `run` перехватывается ДО `Core` (старый `Run`-command завязан на `Application`).
- **Аргументы:** `ApplicationArguments` (`--port/--host/-w`), приоритет CLI > .env >
  дефолт. `--profile` выкинут (§8).
- **Boot order:** `Kernel::init` рано (курица-яйцо); затем ОДИН скан с коллекторами
  `DICollector` + `ConfigurationCollector` + `AsyncCollector` +
  `ImplementorCollector(WebConfigurer/LoggingConfigurer)`; после скана — apply
  logging → cors → imports. Кэш `Scanner` хранит только список FQCN → добавление
  коллекторов безопасно.

---

## 📦 Статус реализации (готово в коде)

Файлы: `src/WinterApplication.php`, `src/App/{ApplicationArguments,Scope}.php`,
`src/App/Attribute/{Configuration,Bean,Value,Import}.php`,
`src/Collector/ConfigurationCollector.php`,
`src/App/Config/{WebConfigurer,WebConfigurerAdapter,CorsRegistry,ServerSettings,LoggingConfigurer,ChannelRegistry}.php`.
Тесты: `tests/App/*` (11). **Весь сьют: 1416 зелёных.**

Схема потока: `doc/winter-application-flow.md`.

## ⬜ Осталось (отдельными шагами)
- 🟡 **Health/Actuator** — переписать индикатор + `call health` + `#[EnableActuator(port)]`.
- 🟡 **WebSocket** — порт движка (`Component::websocket()` пока throw).
- 🟡 **Starter-autoconfig** — `composer.json extra.winter` (сейчас только `#[Import]`).
- ⬜ **Back-compat** — сосуществование/удаление `BaseBoot`/`Application`; демо-`bootstrap`
  на `WinterApplication`.
- ⬜ **Swoole-валидация** — `serveHttp` локально не проверялся (нет swoole на dev-боксе).

> Полный черновой обзор со всеми примерами: `docs/winter-application-redesign.md`.
