<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Client\Errors\A2AClientError;

/**
 * A 405 from a v0.3 server, so CompatRestTransport::subscribe() can retry
 * with GET (v0.3 servers only served GET there).
 *
 * @internal
 */
final class MethodNotAllowed extends A2AClientError {}
