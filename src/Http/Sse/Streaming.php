<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Sse;

/**
 * A response that lasts as long as its client does — not under the request's deadline
 * (`SERVER_REQUEST_TIMEOUT`); a `#[Timeout]` of its route still caps it.
 */
interface Streaming
{
}
