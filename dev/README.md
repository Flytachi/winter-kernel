# dev — песочница ядра

Проект, в котором ядро подключено из `../` **симлинком**: любая правка в `src/` или
`console/` сразу видна здесь, без `composer update`.

```
bootstrap.php   ← класс приложения: что включено (раскомментируй нужное)
main/           ← код песочницы: контроллер, процессы, демоны, планировщик
resources/      ← lang/, static/ (отдаётся с корня: /winter/logo.svg)
.env            ← DEBUG, LOG_*, WINTER_THREAD_RUNNER
wDevRunner      ← раннер dispatch() для песочницы (см. ниже)
.winter/, logs/ ← создаются сами, в git не идут
```

## Первый запуск

```bash
cd dev
composer install
cp .env.example .env   # если .env ещё нет
php call app info      # что объявлено и как запустится
```

В `.env` должен быть путь к `wDevRunner` — абсолютный, у каждого свой:

```dotenv
WINTER_THREAD_RUNNER=/полный/путь/до/winter-kernel/dev/wDevRunner
```

Зачем: `vendor/bin/wKernelRunner` ищет `bootstrap.php` на три папки выше себя, а через
симлинк это указывает в никуда. Без этой строки не работают `process … start -d`,
`dispatch()` и подхват процессов приложением.

## Запуск

```bash
php call run               # приложение (PID 1) — Http :8000 + всё объявленное
php call run dev           # то же с перезапуском при правке кода
php call app status        # компоненты: объявленные и подхваченные
php call app stop          # остановить (из другого терминала)
```

Проверить:

```bash
curl -i localhost:8000/actuator/health
curl localhost:8000/actuator            # открыты все разделы: expose ['*']
curl -o /dev/null -w '%{http_code}\n' localhost:8000/winter/logo.svg
```

## Процессы, демоны, планировщик

В `main/Process/` лежат готовые случаи:

| Класс | Что проверяет |
| --- | --- |
| `DemoProcess` | короткая задача: 6 корутин по 2 одновременно, отработал и вышел |
| `LongDemo` | долгий процесс с `spawn()`, мягкая остановка по SIGTERM |
| `SignalDemo` | сигналы: SIGTERM/SIGINT будит из долгого `sleep()` сразу |
| `ConsumerDemo` | IDLE/BUSY: остановка дожидается конца BUSY-единицы |
| `SendProc` | процесс-воркер, сам по себе или под `FleetDaemon` |
| `StableDaemon` | демон: мягкая остановка всего флота |
| `FleetDaemon` | демон с внешним классом воркера (`SendProc`) |
| `AutoscaleDaemon` | масштабирование флота 1 → 5 → 2 |
| `CrashDaemon` | воркер падает: перезапуски с паузой → FAILED |
| `HungDaemon` | воркер завис: сторож живости, SIGKILL, перезапуск |
| `NeverDaemon` | упавший воркер не заменяется |

```bash
php call process list
php call process Main.Process.LongDemo start      # при работающем приложении — подхват
php call process Main.Process.LongDemo stop
php call daemon Main.Process.FleetDaemon start -d
php call schedule list                            # задачи из main/Schedule/DemoTasks
```

Объявить как компонент приложения — атрибутом в `bootstrap.php`
(`#[EnableProcess(...)]`, `#[EnableDaemon(...)]`, `#[EnableScheduler]`) или кодом в
`components()` — там есть закомментированные примеры, включая `command()`.

## Логи

`LOG_OUTPUT=file` → `logs/sys-<дата>.log`; HTTP-канал (`LOG_HTTP_OUTPUT=stdout`) — в
терминал. Смотреть: `tail -f logs/sys-*.log`.

Без `monolog/monolog` логгер молча становится `NullLogger` — поэтому он в `require`.

## Сбросить состояние

```bash
php call app stop
rm -rf .winter logs        # записи процессов, кеш DI/прокси, логи
```
