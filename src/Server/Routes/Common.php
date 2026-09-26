<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Types\ListTasksResponse;
use Google\Protobuf\Internal\Message as ProtobufMessage;

/**
 * JSON helpers shared by the dispatchers.
 *
 * Mirrors a2a-python: serialize_list_tasks_response() in
 * src/a2a/server/routes/common.py.
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class Common
{
    private function __construct() {}

    /**
     * A protobuf message as a JSON-ready value. Decoded to objects so empty
     * messages and maps stay `{}` rather than turning into `[]`.
     */
    public static function toJsonValue(ProtobufMessage $message): mixed
    {
        return json_decode($message->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * ListTasks always includes its paging fields, even when empty (Python
     * uses always_print_fields_with_no_presence=True), and drops artifacts
     * unless they were asked for.
     */
    public static function serializeListTasksResponse(ListTasksResponse $response, bool $includeArtifacts): \stdClass
    {
        $result = self::toJsonValue($response);
        if (!$result instanceof \stdClass) {
            $result = new \stdClass();
        }
        if (!isset($result->tasks) || !is_array($result->tasks)) {
            $result->tasks = [];
        }
        $result->nextPageToken ??= '';
        $result->pageSize ??= $response->getPageSize();
        $result->totalSize ??= $response->getTotalSize();

        foreach ($result->tasks as $task) {
            if (!$task instanceof \stdClass) {
                continue;
            }
            $task->id ??= '';
            $task->contextId ??= '';
            $task->history ??= [];
            if ($includeArtifacts) {
                $task->artifacts ??= [];
            } else {
                unset($task->artifacts);
            }
        }

        return $result;
    }

    /**
     * Echoes the extensions activated for the request in the
     * `A2A-Extensions` response header (spec: the response SHOULD list
     * them). No header when none were activated.
     */
    public static function withActivatedExtensions(\Psr\Http\Message\ResponseInterface $response, \A2A\Server\ServerCallContext $context): \Psr\Http\Message\ResponseInterface
    {
        if ($context->activatedExtensions === []) {
            return $response;
        }

        return $response->withHeader(\A2A\Extensions\Common::HTTP_EXTENSION_HEADER, implode(',', $context->activatedExtensions));
    }
}
