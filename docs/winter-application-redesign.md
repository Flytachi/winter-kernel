# WinterApplication — редизайн загрузчика (proposal)

> Цель: убрать «бог-класс» `Boot`, где один класс держит `components()`,
> `configure()`, `providers()`, `channels()`, `httpCors()`, `health()`,
> `plugins()`, `swooleConfig()`. Приходим к Spring-модели: **тонкий entry-класс
> + конфигурация, разъехавшаяся по классам, которые находит сканер.**
>
> Это разбор дизайна, а не финальный код. Каждый раздел показывает: (1) Spring-
> аналог, (2) что даёт фреймворк, (3) что пишет кодер.

---

## 0. Как это выглядит «до» и «после»

### Сейчас (один класс на всё)

```php
class Boot extends Application
{
    protected static function components(): array { /* http, process... */ }
    protected static function configure(): void   { Kernel::init(...); }
    protected static function providers(Container $c): void { /* биндинги */ }
    protected static function channels(): void    { /* каналы логов */ }
    protected static function httpCors(): void     { Cors::configure(...); }
    protected static function health(): void       { Health::configure(...); }
    protected static function plugins(): void      { Plugin::registry(...); }
    public    static function swooleConfig(): array { return [...]; }
}
```

### После (тонкий вход + разнесённая конфигурация)

```php
#[EnableWeb(port: 8000)]
#[EnableScheduling]
#[EnableDaemon(Emails::class)]
#[EnablePlugin('acme/auth-plugin', '/auth')]
final class App extends WinterApplication
{
    public static function main(array $args): never
    {
        return self::run(App::class, $args);
    }
}
```

Всё остальное (беды/beans, CORS, health, каналы, настройки сервера) — **обычные
классы в проекте**, которые сканер сам находит. `App` больше не знает про них.

---

## 1. Точка входа: `WinterApplication` + `main(array $args)`

### Spring-аналог

```java
@SpringBootApplication
public class MyApp {
    public static void main(String[] args) {
        SpringApplication.run(MyApp.class, args);   // единственный вход
    }
}
```

`SpringApplication.run(...)` делает всё: читает `application.properties`, сканирует
`@Component`, поднимает встроенный сервер. Аргументы `--server.port=8081`
перекрывают свойства (Spring это зовёт *relaxed binding*).

### Что даёт фреймворк

Новый абстрактный класс `WinterApplication` (заменяет `BaseBoot`/`Application`):

```php
namespace Flytachi\Winter\K2;

abstract class WinterApplication
{
    /**
     * Единственный вход приложения. Парсит аргументы, поднимает ядро, сканирует
     * проект, применяет конфигураторы и либо поднимает компоненты, либо выполняет
     * console-команду (make/daemon/...).
     *
     * @param class-string<WinterApplication> $appClass
     * @param array $args сырой $argv (имя скрипта в [0])
     */
    final public static function run(string $appClass, array $args): never
    {
        $arguments = ApplicationArguments::parse($args);   // --port=8080 --profile=prod ...
        // ... bootstrap ядра + скан + применение конфигураторов + запуск ...
    }

    /**
     * Опциональный override путей ядра — нужен только если каталоги проекта
     * нестандартные. По умолчанию pathRoot выводится из расположения App.
     */
    protected static function configure(ApplicationArguments $args): void
    {
        Kernel::init(pathRoot: static::rootPath());
    }
}
```

### Что пишет кодер

Файл `App.php` (класс приложения):

```php
#[EnableWeb(port: 8000)]
final class App extends WinterApplication
{
    public static function main(array $args): never
    {
        return self::run(App::class, $args);
    }
}
```

Файл `call` (единственный launcher, как `java -jar`):

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/App.php';

App::main($argv);   // ← один вход на всё: и `call run`, и `call make ...`
```

### Аргументы (`ApplicationArguments`)

`--port`, `--host`, `--profile`, `--<любой ключ>` парсятся в типизированный объект.
Они кладутся **поверх** `.env`/атрибутов как override:

```bash
php call --port=8080                 # перебить порт web-компонента
php call --profile=prod              # выбрать профиль (аналог Spring profiles)
php call make -c UserController      # console-команда — тоже через App::main
```

`--port=8080` побеждает `#[EnableWeb(port: 8000)]`. Приоритет:
**аргумент CLI > .env > атрибут/дефолт.**

---

## 2. Beans / DI-биндинги: `#[Configuration]` + `#[Bean]`

> Заменяет `providers(Container $c)`.

### Spring-аналог

```java
@Configuration
public class AppConfig {
    @Bean
    public MailerInterface mailer(@Value("${mail.host}") String host) {
        return new SmtpMailer(host);
    }
}
```

Класс с `@Configuration`, методы с `@Bean` возвращают объекты — Spring кладёт их в
контейнер. Тип возврата = ключ бина. Аргументы метода — автоинжектятся.

### Что даёт фреймворк

- Атрибут `#[Configuration]` (маркер класса-конфигурации).
- Атрибут `#[Bean]` (маркер фабричного метода).
- Атрибут `#[Value('ENV_KEY')]` — инжект значения из `.env` (аналог `@Value`).
- Новый коллектор `ConfigurationCollector`: на скане находит `#[Configuration]`-
  классы, для каждого `#[Bean]`-метода регистрирует фабрику в `Container`
  (ключ = тип возврата метода, аргументы = autowire).

### Что пишет кодер

```php
namespace Main\Config;

use Flytachi\Winter\K2\App\Attribute\Configuration;
use Flytachi\Winter\K2\App\Attribute\Bean;
use Flytachi\Winter\K2\App\Attribute\Value;

#[Configuration]
final class AppConfig
{
    #[Bean]
    public function mailer(#[Value('MAIL_HOST')] string $host): MailerInterface
    {
        return new SmtpMailer($host);
    }

    // Аргументы бинов автоинжектятся из контейнера — как в конструкторах.
    #[Bean]
    public function cache(LoggerInterface $logger): CacheInterface
    {
        return new RedisCache(env('REDIS_URL'), $logger);
    }
}
```

Никаких `$c->bind(...)` в entry-классе. Хочешь новый сервис — создаёшь метод в
любом `#[Configuration]`-классе, сканер подхватит. Простые `#[Singleton]`/
`#[Service]`-классы (авто-DI по атрибуту) работают как раньше — `#[Bean]` нужен
только когда сборку объекта нельзя выразить атрибутом (интерфейс→реализация,
фабрика, скаляр из env).

---

## 3. CORS: интерфейс `WebConfigurer`

> Заменяет `httpCors()`.

### Spring-аналог

```java
@Configuration
public class WebConfig implements WebMvcConfigurer {
    @Override
    public void addCorsMappings(CorsRegistry registry) {
        registry.addMapping("/api/**")
                .allowedOrigins("https://app.example.com")
                .allowCredentials(true);
    }
}
```

Реализуешь интерфейс `WebMvcConfigurer`, Spring находит его и вызывает
`addCorsMappings(...)` при старте.

### Что даёт фреймворк

- Интерфейс `WebConfigurer` с методом `configureCors(CorsRegistry $cors): void`.
- Класс `CorsRegistry` — fluent-обёртка, которая внутри зовёт существующий
  `Cors::configure(...)`.
- На boot: `ImplementorCollector(WebConfigurer::class)` находит все реализации и
  вызывает их (аналог того, как сейчас находятся Controller'ы).

```php
interface WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void;
}
```

### Что пишет кодер

```php
namespace Main\Config;

use Flytachi\Winter\K2\Http\Cors\WebConfigurer;
use Flytachi\Winter\K2\Http\Cors\CorsRegistry;

final class WebConfig implements WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void
    {
        $cors->allowedOrigins('https://app.example.com')
             ->allowedHeaders('Content-Type', 'Authorization', 'X-Request-Id')
             ->exposeHeaders('X-Request-Id')
             ->allowCredentials(true)
             ->maxAge(3600);
    }
}
```

Нет CORS-класса → политика дефолтная (wildcard). Per-route по-прежнему через
`#[CrossOrigin]` на контроллере — этот механизм не трогаем.

---

## 4. Health / Actuator: авто-discovery `HealthIndicator`

> Заменяет `health()`.

### Spring-аналог

```java
@Component
public class DatabaseHealthIndicator implements HealthIndicator {
    @Override
    public Health health() {
        return db.ping() ? Health.up().build() : Health.down().build();
    }
}
```

Просто объявляешь `@Component`, реализующий `HealthIndicator`. Actuator сам его
находит и агрегирует в `/actuator/health`. Никакой регистрации.

### Что даёт фреймворк

- Интерфейс `HealthIndicator` с методом `health(): Health`.
- Коллектор находит все реализации и регистрирует в агрегаторе `/actuator/health`.
- Сам actuator включается атрибутом `#[EnableActuator]` на App (либо по умолчанию
  для web-компонента). Защита middleware — параметр атрибута.

### Что пишет кодер

Включить actuator (на App-классе):

```php
#[EnableActuator(middleware: InternalOnlyMiddleware::class)]
final class App extends WinterApplication { /* ... */ }
```

Добавить свою проверку (обычный класс, сканер найдёт):

```php
namespace Main\Health;

use Flytachi\Winter\K2\Http\Health\HealthIndicator;
use Flytachi\Winter\K2\Http\Health\Health;

final class DatabaseHealth implements HealthIndicator
{
    public function __construct(private Db $db) {}   // автоинжект

    public function health(): Health
    {
        return $this->db->ping()
            ? Health::up()->withDetail('latency_ms', $this->db->latency())
            : Health::down()->withDetail('reason', 'connection failed');
    }
}
```

---

## 5. Каналы логов: `.env` + опциональный `LoggingConfigurer`

> Заменяет `channels()`.

### Spring-аналог

В Spring каналы/аппендеры настраиваются в `logback-spring.xml` или через
`application.properties` — почти никогда в коде. Только сложные случаи — через код.

### Что даёт фреймворк

- Базовые каналы (`http`, `sys`) уже регистрируются в `Kernel::init`.
- Кастомные каналы — из `.env` (как сейчас, `LOG_{NAME}_*`), плюс объявление имён.
- Для кода — интерфейс `LoggingConfigurer` с `configureChannels(ChannelRegistry)`.

### Что пишет кодер

Чаще всего — только `.env`:

```dotenv
LOG_JOB_LEVEL=debug
LOG_JOB_OUTPUT=file
LOG_JOB_FILE=/var/log/app/job.log
```

Если нужен код (динамические каналы):

```php
namespace Main\Config;

use Flytachi\Winter\K2\Logging\LoggingConfigurer;
use Flytachi\Winter\K2\Logging\ChannelRegistry;

final class LoggingConfig implements LoggingConfigurer
{
    public function configureChannels(ChannelRegistry $channels): void
    {
        $channels->add('job');
        $channels->add('audit');
    }
}
```

Использование в коде не меняется:
`LoggerFactory::getLogger(MyJob::class, 'job')->info('started')`.

---

## 6. Плагины: атрибуты `#[EnablePlugin]`

> Заменяет `plugins()`.

### Spring-аналог

Модульность в Spring — это `@Import` и стартеры (`spring-boot-starter-*`). Подключил
зависимость → авто-конфигурация подхватилась. Ближайшая калька — декларативные
`@Enable*`/`@Import` на главном классе.

### Что даёт фреймворк

- Атрибут `#[EnablePlugin(package, prefix, required)]` — повторяемый.
- На boot читаются атрибуты App-класса → вызывается существующий
  `Plugin::registry(...)`.

### Что пишет кодер

```php
#[EnablePlugin('acme/auth-plugin', '/auth')]
#[EnablePlugin('acme/billing-plugin', '/billing')]
#[EnablePlugin('acme/experimental', '/x', required: false)]
final class App extends WinterApplication { /* ... */ }
```

Читается сверху класса, декларативно. `src/` каждого плагина сканируется
автоматически — как сейчас.

---

## 7. Настройки сервера (Swoole): `ServerConfigurer` / `.env`

> Заменяет `swooleConfig()`.

### Spring-аналог

```properties
server.port=8080
server.tomcat.threads.max=200
```

Настройки встроенного сервера — свойства `server.*`. Для кода —
`WebServerFactoryCustomizer`.

### Что даёт фреймворк

- `.env`-свойства `SERVER_*` (workers, max_request, ...).
- Опциональный `ServerConfigurer` с `configure(ServerSettings $s)` для тонкой
  настройки в коде (проброс в `\Swoole\Http\Server::set()`).

### Что пишет кодер

`.env`:

```dotenv
SERVER_WORKERS=8
SERVER_MAX_REQUEST=5000
```

или код:

```php
final class ServerConfig implements ServerConfigurer
{
    public function configure(ServerSettings $s): void
    {
        $s->workers(swoole_cpu_num() * 2)
          ->maxRequest(5000)
          ->maxRequestGrace(500);
    }
}
```

---

## 8. Что запускается: атрибуты `#[Enable*]`

> Заменяет `components()`.

### Spring-аналог

```java
@EnableScheduling      // включить планировщик
@EnableAsync           // включить async
@SpringBootApplication
public class MyApp { ... }
```

В Spring «что умеет приложение» — это набор `@Enable*` + наличие сервера в classpath.

### Что даёт фреймворк

Атрибуты на App-классе, читаются на boot и превращаются в `Component`-манифест:

| Атрибут | Аналог сейчас |
|---|---|
| `#[EnableWeb(host, port)]` | `Component::http(...)` |
| `#[EnableScheduling]` | `Component::scheduler()` |
| `#[EnableProcess(Class::class)]` | `Component::process(...)` |
| `#[EnableDaemon(Class::class)]` | `Component::daemon(...)` |

### Что пишет кодер

```php
#[EnableWeb(port: 8000)]
#[EnableScheduling]
#[EnableProcess(KernelSys::class)]
#[EnableDaemon(Emails::class)]
final class App extends WinterApplication
{
    public static function main(array $args): never
    {
        return self::run(App::class, $args);
    }
}
```

- Есть `#[EnableWeb]` → поднимается Swoole HTTP + компаньоны рядом (addProcess).
- Нет `#[EnableWeb]` → headless (только фоновые).
- `--port=8080` из CLI перебивает порт.

> Развилка, которую надо решить: `#[Enable*]`-атрибуты **или** оставить метод
> `components(): array` (гибче для условной сборки — `if (env(...))`), **или** оба
> (атрибуты для типового, метод как escape-hatch).

---

## 9. Порядок boot (что за чем)

```
App::main($argv)
  → WinterApplication::run(App::class, $argv)
     1. ApplicationArguments::parse($argv)      — --port, --profile, ...
     2. configure(args) → Kernel::init(...)     — пути, .env, логгер (РАНО, до скана)
     3. DI-скан проекта (существующий Scanner), коллекторы:
          • DICollector           — #[Singleton]/#[Service]/... (как сейчас)
          • AsyncCollector        — #[Async]-прокси (как сейчас)
          • ConfigurationCollector— #[Configuration]/#[Bean]  (НОВОЕ)
          • ImplementorCollector  — WebConfigurer / LoggingConfigurer /
                                     ServerConfigurer / HealthIndicator (НОВОЕ)
     4. Применить конфигураторы: logging → cors → health
     5. Прочитать атрибуты App: #[Enable*], #[EnablePlugin], #[EnableActuator]
     6. Диспетчеризация:
          • есть console-команда в args (make/daemon/schedule/...) → выполнить её
          • иначе → поднять компоненты (serve): Swoole + компаньоны / headless
```

Ключевой нюанс (курица-яйцо): шаг 2 (`Kernel::init` — пути/env/лог) **обязан**
отработать до скана, поэтому он остаётся на App-классе/конвенции и **не** может быть
discovered-классом. Всё остальное — находится сканом.

---

## 10. Итог: какие классы появляются

### Даёт фреймворк (winter-kernel)

| Класс / атрибут | Роль |
|---|---|
| `WinterApplication` | базовый entry-класс, `run()` / `main()` |
| `ApplicationArguments` | парсинг `--key=value` из argv |
| `#[Configuration]`, `#[Bean]`, `#[Value]` | beans вместо `providers()` |
| `ConfigurationCollector` | сбор `#[Bean]`-фабрик на скане |
| `WebConfigurer` + `CorsRegistry` | CORS вместо `httpCors()` |
| `HealthIndicator` (+ авто-агрегатор) | health вместо `health()` |
| `LoggingConfigurer` + `ChannelRegistry` | каналы вместо `channels()` |
| `ServerConfigurer` + `ServerSettings` | сервер вместо `swooleConfig()` |
| `#[EnableWeb]`, `#[EnableScheduling]`, `#[EnableProcess]`, `#[EnableDaemon]`, `#[EnablePlugin]`, `#[EnableActuator]` | манифест вместо `components()`/`plugins()` |

### Пишет кодер (в своём проекте)

| Файл | Что это |
|---|---|
| `App.php` | тонкий класс с `main()` + `#[Enable*]` |
| `call` | `App::main($argv)` |
| `Config/AppConfig.php` | `#[Configuration]` с `#[Bean]`-методами (опц.) |
| `Config/WebConfig.php` | `implements WebConfigurer` (опц., CORS) |
| `Health/DatabaseHealth.php` | `implements HealthIndicator` (опц.) |
| `Config/LoggingConfig.php` | `implements LoggingConfigurer` (опц.) |
| `Config/ServerConfig.php` | `implements ServerConfigurer` (опц.) |

**Всё «опц.» — реально опционально**: нет класса → дефолт фреймворка. App-класс
худеет до `main()` + атрибутов; конфиг перестаёт торчать protected-методами в API
наследника (это отдельно важно по твоему принципу инкапсуляции).

---

## 11. Открытые развилки (решить до кода)

1. **Config-механизм** — full Spring (Configuration/Bean + Configurer-интерфейсы)
   / лёгкий (один `AppConfig` с перенесёнными хуками) / гибрид.
2. **Components** — `#[Enable*]`-атрибуты / метод `components()` / оба.
3. **Console vs serve** — `App::main` диспетчеризует и команды, и подъём приложения
   (нужно решить: `call run` остаётся отдельным словом, или подъём = дефолт без
   команды).
4. **Back-compat** — оставляем ли старый `BaseBoot`/`Application` как deprecated
   слой на переходный период, или рубим сразу (Swoole-only приоритет уже задан).
