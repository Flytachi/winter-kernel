---
name: K2 Layer — Route/Http унификация
description: Новый K2 namespace — dual-mode (Swoole+FPM) Route/Http слой поверх старого Kernel
type: project
---

Создан параллельный namespace `Flytachi\Winter\K2\` в `src/K2/`.
Старый `Kernel` namespace нетронут — пользователь сам уберёт его позже.

**Статус: Route слой завершён (апрель 2026)**

## Структура src/K2/
- `Http/Contracts/` — HttpRequest, HttpResponse интерфейсы
- `Http/Adapter/` — SwooleRequest, SwooleResponse, FpmRequest, FpmResponse
- `Http/Header` — dual-mode (Coroutine ctx / static), `init(HttpRequest)`
- `Http/Middleware/` — MiddlewareInterface, MiddlewareException
- `Http/Request/` — RequestObject, RequestException, 6 аннотаций (#[PathVariable] и т.д.)
- `Http/Response/` — ResponseEntity, ResponseException, ExceptionResponseBase,
  ExceptionWrapper (#[AdviceException] сканер), ContentType, AcceptHeaderParser
- `Http/ParameterResolver` — 12 стратегий инъекции
- `Route/Annotation/` — 7 маппинг-аннотаций
- `Route/` — Router (handle(HttpRequest,HttpResponse)), MappingScanner, Dispatcher, Route, RouteResult, MemoryWatcher
- `Stereotype/` — Controller, ControllerInterface, Middleware

## Ключевые решения
- `Router::handle(HttpRequest, HttpResponse)` — единая точка входа
- `Router::fromScan($dir)` автоматически конфигурирует ExceptionWrapper
- ExceptionWrapper — in-memory кеш, без файлового кеша, без Kernel зависимости
- ExceptionResponseBase — concrete (не abstract), является дефолтным обработчиком
- MemoryWatcher — Swoole-specific, wraps callable перед передачей в on('request')

## Точки входа
- Swoole: `$router->handle(new SwooleRequest($req), new SwooleResponse($res))`
- FPM: `$router->handle(new FpmRequest(), new FpmResponse())`

## Тесты (tests/main/)
- TestController, AvgController — переведены на K2 (Controller, GetMapping, ResponseEntity)
- ExceptionResp, ExceptionResp2 — переведены на K2 (ExceptionResponseBase, AdviceException K2)
- contentData() вместо content() (новое имя в ExceptionResponseBase)
- debugData() вместо debugger() (новое имя в ExceptionResponseBase)

## Следующие шаги (не сделано)
- K2 Kernel (инит окружения, логирование, timezone)
- Plugin routing
- Файловый кеш маппинга для FPM
- Health endpoint
