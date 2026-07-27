# WinterApplication — схема работы (как что движется)

> Карта нового загрузчика: файлы, поток управления, поток данных. Читать при
> тестах и детальном разборе. `Boot`/`Application` НЕ тронуты — это параллельный,
> самостоятельный вход. Всё покрыто тестами (`tests/App`, суммарно 1416 зелёных).

---

## 1. Карта файлов (что появилось)

| Файл | Роль |
|---|---|
| `src/WinterApplication.php` | **точка входа**: `main()`/`run()` → boot → dispatch → serve |
| `src/App/ApplicationArguments.php` | парсинг `argv` (`--port`, `-w`, command/sub) |
| `src/App/Scope.php` | enum scope бина: Singleton / Transient / Request |
| `src/App/Attribute/Configuration.php` | метка класса-конфигурации (Spring `@Configuration`) |
| `src/App/Attribute/Bean.php` | метка фабричного метода (Spring `@Bean`) |
| `src/App/Attribute/Value.php` | инжект значения из `.env` в параметр бина (`@Value`) |
| `src/App/Attribute/Import.php` | подключение пакета-плагина (`@Import`), repeatable |
| `src/Collector/ConfigurationCollector.php` | регистрирует `#[Bean]`-методы в контейнер |
| `src/App/Config/WebConfigurer.php` | контракт: CORS + тюнинг сервера (`WebMvcConfigurer`) |
| `src/App/Config/WebConfigurerAdapter.php` | пустые дефолты обоих методов (адаптер) |
| `src/App/Config/CorsRegistry.php` | fluent-билдер CORS → `Cors::configure()` |
| `src/App/Config/ServerSettings.php` | опции Swoole из `.env` + тюнинг |
| `src/App/Config/LoggingConfigurer.php` | контракт: доп. лог-каналы |
| `src/App/Config/ChannelRegistry.php` | билдер каналов → `Kernel::channel()` |
| `tests/App/*` | тесты: аргументы + Beans-коллектор |

Переиспользуется как есть: `Kernel`, `Container`, `Scanner`, `DICollector`,
`AsyncCollector`, `ImplementorCollector`, `Cors`, `Plugin`, `Router`, `DevWatcher`,
`ForkReset`, `Component`/`ComponentKind`, консольный `Core`.

---

## 2. Общий поток (сверху вниз)

```
 call  →  App::main($argv)                         (dev/call — единственный вход)
              │
              ▼
      WinterApplication::run($argv)
              │
   ┌──────────┴───────────────────────────────────────────────┐
   │ 1. ApplicationArguments::parse($argv)                     │  argv → объект args
   │      command / sub / --port / -w / raw                    │
   ├───────────────────────────────────────────────────────────┤
   │ 2. bootstrap($args)   ← СЕРДЦЕ (см. §3)                    │  ядро + скан + конфиг
   ├───────────────────────────────────────────────────────────┤
   │ 3. DISPATCH по command:                                   │
   │      'run' | 'run dev'     → serve($watch,$args)  (§5)     │  поднять приложение
   │      пусто | make|cfg|...  → new Core($argv)->run()       │  консоль (пусто → Help)
   └───────────────────────────────────────────────────────────┘
```

Решение по диспетчеризации: **поднять приложение только по `call run`/`call run
dev`**; голый `call` и любой другой глагол = консольная команда (тот же `Core`, что
и в старом `cli()`; голый `call` → `Help`). `run` перехватывается ДО `Core` (старый
`Run`-command завязан на `Application`, его не трогаем).

---

## 3. Фаза boot — `bootstrap($args)` (откуда что берётся)

```
bootstrap($args)
  │
  ├─ configure($args)                    → Kernel::init(pathRoot: rootPath())
  │                                         .env, логгер (sys/http), timezone, thread
  │                                         [РАНО: до скана — курица-яйцо]
  │
  ├─ $c = Container::init()
  │
  └─ ОДИН скан проекта (Scanner::run(pathRoot, cache di.php)):
        collect(DICollector)             → #[Singleton]/#[Request]/#[Transient]
        collect(ConfigurationCollector)  → #[Configuration] + #[Bean] → фабрики в $c
        collect(AsyncCollector)          → #[Async]-прокси
        collect(ImplementorCollector(WebConfigurer))     → список классов
        collect(ImplementorCollector(LoggingConfigurer)) → список классов
        execute()
        │
        ▼  после скана — ПРИМЕНИТЬ найденное:
        applyLogging($c, найденные LoggingConfigurer)  → ChannelRegistry → Kernel::channel()
        applyCors($c,    найденные WebConfigurer)       → CorsRegistry   → Cors::configure()
                                                         (+ запомнить классы для §5)
        applyImports()  → читает #[Import] на App-классе → Plugin::registry()
```

Ключевое: **конфигурация не вызывается как хуки на App-классе — она НАХОДИТСЯ
сканером** (обычные классы в проекте) и применяется после скана. App-класс знает
только `components()` + `configure()` + свои `#[Import]`-атрибуты.

### Как `#[Bean]` попадает в контейнер (ConfigurationCollector)

```
#[Configuration] class AppConfig
  ├─ #[Bean] cache(): CacheInterface        → $c->singleton(CacheInterface, factory)
  ├─ #[Bean(scope: Transient)] q(): Query   → $c->transient(Query, factory)
  └─ сам AppConfig                          → $c->singleton(AppConfig)  (общий инстанс)

factory(при resolve):
  $config = $c->make(AppConfig)             ← общий инстанс конфигурации
  аргументы метода:
     #[Value('KEY', def)] → env('KEY', def) ← скаляр из .env
     иной тип             → $c->make(тип)   ← автowire
  return $config->method(...аргументы)
```
> Бин ОБЯЗАН возвращать объект (контейнер инжектит свойства в результат) — скаляры
> только через `.env`/`#[Value]`. Иначе коллектор кидает понятную ошибку.

---

## 4. Как находятся конфигураторы (discovery)

Один и тот же механизм для CORS/сервера/каналов — существующий `ImplementorCollector`:

```
Кодер кладёт класс:                        Фреймворк на скане:
  class WebConfig                            ImplementorCollector(WebConfigurer)
      implements WebConfigurer      ──────►    ->getResult() = [WebConfig, ...]
  { configureCors(...) }                     после скана: $c->make(WebConfig)
                                                          ->configureCors($registry)
                                             $registry->apply() → Cors::configure()
```

Ноль регистрации. Положил файл → нашёлся → применился. Удалил → дефолт.

---

## 5. Фаза serve — `serve($watch, $args)`

```
serve()
  ├─ components() → классификация:
  │     Http       → $http           (максимум один)
  │     WebSocket  → ⛔ throw (порт легаси-движка ещё не готов)
  │     Process/Daemon/Scheduler → companions[]
  │
  ├─ есть $http?  ──ДА──►  serveHttp()                     (нужен ext-swoole)
  │                          host/port: args --port/--host > Component::http > дефолт
  │                          server->set( ServerSettings::fromEnv() + WebConfigurer::configureServer )
  │                          Router::fromScan(pathRoot) + static(public)
  │                          companions → $server->addProcess(...)   (супервизор)
  │                          workerStart → канал 'http' + CoroutineContext
  │                          companions-child → канал 'sys' + ProcessContext + ForkReset
  │                          $watch → DevWatcher (память + hot-reload через reexec)
  │                          $server->start()
  │
  └─ нет $http?  ──►  serveHeadless()
                        1 компонент  → foreground $class::start()
                        несколько    → pcntl fork на каждый + waitpid + форвард SIGTERM/SIGINT
                        (работает и без swoole)
```

---

## 6. Что пишет кодер (полный пример)

```php
// App.php — тонкий класс приложения
#[Import('acme/auth-plugin', '/auth')]                 // плагин (опц.)
final class App extends WinterApplication
{
    protected static function configure(ApplicationArguments $args): void
    {
        Kernel::init(pathRoot: __DIR__);               // или убрать → rootPath() сам выведет
    }

    protected static function components(): array
    {
        return [
            Component::http(port: 8000),               // web (опц.)
            Component::daemon(Emails::class),
            Component::scheduler(),
        ];
    }
}
```

```php
// call — единственный launcher
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/App.php';
App::main($argv);
```

Опциональные конфиг-классы (кладёшь — находятся сами):

```php
#[Configuration]
final class AppConfig
{
    #[Bean]
    public function mailer(#[Value('MAIL_HOST')] string $host): MailerInterface
    {
        return new SmtpMailer($host);
    }
}

final class WebConfig extends WebConfigurerAdapter
{
    public function configureCors(CorsRegistry $cors): void
    {
        $cors->allowedOrigins('https://app.example.com')->allowCredentials();
    }
    public function configureServer(ServerSettings $s): void
    {
        $s->workers(8)->maxRequest(5000);
    }
}
```

Как запускать:

```bash
php call                 # help (консоль, Core → Help)
php call run             # поднять приложение, DevWatcher off
php call run dev         # + DevWatcher (память + hot-reload)
php call run --port=8080 # override порта
php call make -c UserController   # консольная команда (через Core)
php call daemon main.Emails start # standalone-компонент
```

---

## 7. Каналы логов (куда что пишется)

| Контекст | Канал | Где ставится |
|---|---|---|
| HTTP-запрос (worker) | `http` | `serveHttp` on('workerStart') + CoroutineContext |
| master / companions / console / framework | `sys` | run() перед Core; child-замыкания addProcess/headless |

Кастомные каналы — `.env` (`LOG_{NAME}_*`) или `LoggingConfigurer`.

---

## 8. Что ОТЛОЖЕНО (не в этом коде)

- 🟡 **WebSocket** — `Component::websocket()` пока `throw` (порт легаси-движка).
- 🟡 **Health/Actuator** — индикатор переписываем; `call health` + `#[EnableActuator(port)]`
  ещё не реализованы.
- 🟡 **Starter-autoconfig** — авто-подключение пакетов через `composer.json extra.winter`
  (сейчас только явный `#[Import]`).
- ⬜ **Back-compat** — сосуществование со старым `Boot`/`Application` (решить).

---

## 9. Чем проверять

```bash
vendor/bin/phpunit tests/App     # тесты нового кода (11)
vendor/bin/phpunit               # весь сьют (1416, App включён в phpunit.xml)
```
> Swoole на dev-боксе не загружен → HTTP-путь `serveHttp` локально не проверяется
> (как и раньше для `Application`); валидируется на timeline. Консоль/headless/
> коллекторы/аргументы — проверяемы и покрыты.
