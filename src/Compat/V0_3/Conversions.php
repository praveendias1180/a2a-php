<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentCardSignature;
use A2A\Types\AgentExtension;
use A2A\Types\AgentInterface;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;
use A2A\Types\APIKeySecurityScheme;
use A2A\Types\Artifact;
use A2A\Types\AuthenticationInfo;
use A2A\Types\AuthorizationCodeOAuthFlow;
use A2A\Types\CancelTaskRequest;
use A2A\Types\ClientCredentialsOAuthFlow;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\HTTPAuthSecurityScheme;
use A2A\Types\ImplicitOAuthFlow;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTaskPushNotificationConfigsResponse;
use A2A\Types\Message;
use A2A\Types\MutualTlsSecurityScheme;
use A2A\Types\OAuth2SecurityScheme;
use A2A\Types\OAuthFlows;
use A2A\Types\OpenIdConnectSecurityScheme;
use A2A\Types\Part;
use A2A\Types\PasswordOAuthFlow;
use A2A\Types\Role;
use A2A\Types\SecurityRequirement;
use A2A\Types\SecurityScheme;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\StreamResponse;
use A2A\Types\StringList;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Constants;
use A2A\Utils\Errors\VersionNotSupportedError;
use Google\Protobuf\Struct;
use Google\Protobuf\Timestamp;
use Google\Protobuf\Value;

/**
 * Converts between A2A v0.3 objects and the v1.0 wire types.
 *
 * v0.3 objects are the v0.3 JSON-RPC JSON shape (`kind` discriminators,
 * lowercase enum values like `input-required`), decoded as \stdClass trees
 * so free-form `metadata` / `data` keep `{}` vs `[]`. Python models these
 * with pydantic classes (a2a.compat.v0_3.types); PHP keeps the decoded JSON.
 *
 * Mirrors a2a-python: src/a2a/compat/v0_3/conversions.py, function for
 * function (`to_core_x` → `toCoreX`, `to_compat_x` → `toCompatX`). The
 * request-level converters take and return the JSON-RPC `params` object.
 */
final class Conversions
{
    /** v0.3 task state → v1.0 TaskState */
    public const COMPAT_TO_CORE_TASK_STATE = [
        'unknown' => TaskState::TASK_STATE_UNSPECIFIED,
        'submitted' => TaskState::TASK_STATE_SUBMITTED,
        'working' => TaskState::TASK_STATE_WORKING,
        'completed' => TaskState::TASK_STATE_COMPLETED,
        'failed' => TaskState::TASK_STATE_FAILED,
        'canceled' => TaskState::TASK_STATE_CANCELED,
        'input-required' => TaskState::TASK_STATE_INPUT_REQUIRED,
        'rejected' => TaskState::TASK_STATE_REJECTED,
        'auth-required' => TaskState::TASK_STATE_AUTH_REQUIRED,
    ];

    private const TERMINAL_COMPAT_STATES = ['completed', 'canceled', 'failed', 'rejected'];

    private function __construct() {}

    // --- Parts, messages, tasks ---------------------------------------------

    public static function toCorePart(\stdClass $compatPart): Part
    {
        $core = new Part();
        $kind = self::kindOf($compatPart);
        $metadata = self::obj($compatPart->metadata ?? null);

        if ($kind === 'text') {
            $core->setText(self::str($compatPart->text ?? null) ?? '');
            if ($metadata !== null) {
                $core->setMetadata(self::toStruct($metadata));
            }
        } elseif ($kind === 'data') {
            $dataPartCompat = false;
            if ($metadata !== null) {
                $meta = clone $metadata;
                $dataPartCompat = (bool) ($meta->data_part_compat ?? false);
                unset($meta->data_part_compat);
                if (get_object_vars($meta) !== []) {
                    $core->setMetadata(self::toStruct($meta));
                }
            }
            $data = $compatPart->data ?? new \stdClass();
            if ($dataPartCompat && $data instanceof \stdClass) {
                // v1.0 data can be any JSON value; v0.3 only objects, so a
                // non-object value travels as {"value": x} + this marker.
                $core->setData(self::toValue($data->value ?? null));
            } else {
                $core->setData(self::toValue(self::obj($data) ?? new \stdClass()));
            }
        } elseif ($kind === 'file') {
            $file = self::obj($compatPart->file ?? null) ?? new \stdClass();
            if (isset($file->bytes)) {
                $decoded = base64_decode(self::str($file->bytes) ?? '', true);
                $core->setRaw($decoded === false ? '' : $decoded);
            } elseif (isset($file->uri)) {
                $core->setUrl(self::str($file->uri) ?? '');
            }
            if (($mime = self::str($file->mimeType ?? null)) !== null && $mime !== '') {
                $core->setMediaType($mime);
            }
            if (($name = self::str($file->name ?? null)) !== null && $name !== '') {
                $core->setFilename($name);
            }
            if ($metadata !== null) {
                $core->setMetadata(self::toStruct($metadata));
            }
        }

        return $core;
    }

    public static function toCompatPart(Part $corePart): \stdClass
    {
        $metadata = $corePart->hasMetadata() ? self::fromStruct($corePart->getMetadata()) : null;

        switch ($corePart->getContent()) {
            case 'text':
                return self::compact(['kind' => 'text', 'text' => $corePart->getText(), 'metadata' => $metadata]);

            case 'data':
                $value = $corePart->getData();
                $data = $value === null ? new \stdClass() : self::fromValue($value);
                if (!$data instanceof \stdClass) {
                    $data = (object) ['value' => $data];
                    $metadata ??= new \stdClass();
                    $metadata->data_part_compat = true;
                }

                return self::compact(['kind' => 'data', 'data' => $data, 'metadata' => $metadata]);

            case 'raw':
            case 'url':
                $file = $corePart->getContent() === 'raw'
                    ? ['bytes' => base64_encode($corePart->getRaw())]
                    : ['uri' => $corePart->getUrl()];
                $file += [
                    'mimeType' => $corePart->getMediaType() !== '' ? $corePart->getMediaType() : null,
                    'name' => $corePart->getFilename() !== '' ? $corePart->getFilename() : null,
                ];

                return self::compact(['kind' => 'file', 'file' => self::compact($file), 'metadata' => $metadata]);
        }

        throw new \ValueError('Unknown part content type: ' . ($corePart->getContent() === '' ? 'none' : $corePart->getContent()));
    }

    public static function toCoreMessage(\stdClass $compatMsg): Message
    {
        $core = new Message([
            'message_id' => self::str($compatMsg->messageId ?? null) ?? '',
            'context_id' => self::str($compatMsg->contextId ?? null) ?? '',
            'task_id' => self::str($compatMsg->taskId ?? null) ?? '',
        ]);
        $referenceTaskIds = self::strList($compatMsg->referenceTaskIds ?? null);
        if ($referenceTaskIds !== []) {
            $core->setReferenceTaskIds($referenceTaskIds);
        }
        $role = self::str($compatMsg->role ?? null);
        if ($role === 'user') {
            $core->setRole(Role::ROLE_USER);
        } elseif ($role === 'agent') {
            $core->setRole(Role::ROLE_AGENT);
        }
        if (($metadata = self::obj($compatMsg->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }
        $extensions = self::strList($compatMsg->extensions ?? null);
        if ($extensions !== []) {
            $core->setExtensions($extensions);
        }
        $parts = [];
        foreach (self::objList($compatMsg->parts ?? null) as $part) {
            $parts[] = self::toCorePart($part);
        }
        $core->setParts($parts);

        return $core;
    }

    public static function toCompatMessage(Message $coreMsg): \stdClass
    {
        $parts = [];
        foreach ($coreMsg->getParts() as $part) {
            $parts[] = self::toCompatPart($part);
        }

        return self::compact([
            'kind' => 'message',
            'messageId' => $coreMsg->getMessageId(),
            'role' => $coreMsg->getRole() === Role::ROLE_USER ? 'user' : 'agent',
            'contextId' => self::nonEmpty($coreMsg->getContextId()),
            'taskId' => self::nonEmpty($coreMsg->getTaskId()),
            'referenceTaskIds' => self::listOrNull($coreMsg->getReferenceTaskIds()),
            // Python checks the Struct's truthiness: an empty one is left out.
            'metadata' => $coreMsg->hasMetadata() && count($coreMsg->getMetadata()?->getFields() ?? []) > 0 ? self::fromStruct($coreMsg->getMetadata()) : null,
            'extensions' => self::listOrNull($coreMsg->getExtensions()),
            'parts' => $parts,
        ]);
    }

    public static function toCoreTaskStatus(\stdClass $compatStatus): TaskStatus
    {
        $core = new TaskStatus();
        $state = self::str($compatStatus->state ?? null);
        if ($state !== null && $state !== '') {
            $core->setState(self::COMPAT_TO_CORE_TASK_STATE[$state] ?? TaskState::TASK_STATE_UNSPECIFIED);
        }
        if (($message = self::obj($compatStatus->message ?? null)) !== null) {
            $core->setMessage(self::toCoreMessage($message));
        }
        if (($timestamp = self::str($compatStatus->timestamp ?? null)) !== null && $timestamp !== '') {
            $core->setTimestamp(self::toTimestamp($timestamp));
        }

        return $core;
    }

    public static function toCompatTaskStatus(TaskStatus $coreStatus): \stdClass
    {
        $state = array_search($coreStatus->getState(), self::COMPAT_TO_CORE_TASK_STATE, true);

        return self::compact([
            'state' => is_string($state) ? $state : 'unknown',
            'message' => $coreStatus->hasMessage() && $coreStatus->getMessage() !== null ? self::toCompatMessage($coreStatus->getMessage()) : null,
            'timestamp' => $coreStatus->hasTimestamp() && $coreStatus->getTimestamp() !== null ? self::timestampToString($coreStatus->getTimestamp()) : null,
        ]);
    }

    public static function toCoreTask(\stdClass $compatTask): Task
    {
        $core = new Task([
            'id' => self::str($compatTask->id ?? null) ?? '',
            'context_id' => self::str($compatTask->contextId ?? null) ?? '',
        ]);
        if (($status = self::obj($compatTask->status ?? null)) !== null) {
            $core->setStatus(self::toCoreTaskStatus($status));
        }
        $history = [];
        foreach (self::objList($compatTask->history ?? null) as $message) {
            $history[] = self::toCoreMessage($message);
        }
        $core->setHistory($history);
        $artifacts = [];
        foreach (self::objList($compatTask->artifacts ?? null) as $artifact) {
            $artifacts[] = self::toCoreArtifact($artifact);
        }
        $core->setArtifacts($artifacts);
        if (($metadata = self::obj($compatTask->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }

        return $core;
    }

    public static function toCompatTask(Task $coreTask): \stdClass
    {
        $history = [];
        foreach ($coreTask->getHistory() as $message) {
            $history[] = self::toCompatMessage($message);
        }
        $artifacts = [];
        foreach ($coreTask->getArtifacts() as $artifact) {
            $artifacts[] = self::toCompatArtifact($artifact);
        }

        return self::compact([
            'kind' => 'task',
            'id' => $coreTask->getId(),
            'contextId' => $coreTask->getContextId(),
            'status' => $coreTask->hasStatus() && $coreTask->getStatus() !== null
                ? self::toCompatTaskStatus($coreTask->getStatus())
                : (object) ['state' => 'unknown'],
            'history' => $history === [] ? null : $history,
            'artifacts' => $artifacts === [] ? null : $artifacts,
            'metadata' => $coreTask->hasMetadata() && $coreTask->getMetadata() !== null ? self::fromStruct($coreTask->getMetadata()) : null,
        ]);
    }

    // --- Push notifications -------------------------------------------------

    public static function toCoreAuthenticationInfo(\stdClass $compatAuth): AuthenticationInfo
    {
        $core = new AuthenticationInfo();
        $schemes = self::strList($compatAuth->schemes ?? null);
        if ($schemes !== []) {
            // v1.0 has one scheme; the first of the v0.3 list wins (Python too).
            $core->setScheme($schemes[0]);
        }
        if (($credentials = self::str($compatAuth->credentials ?? null)) !== null && $credentials !== '') {
            $core->setCredentials($credentials);
        }

        return $core;
    }

    public static function toCompatAuthenticationInfo(AuthenticationInfo $coreAuth): \stdClass
    {
        return self::compact([
            'schemes' => $coreAuth->getScheme() !== '' ? [$coreAuth->getScheme()] : [],
            'credentials' => self::nonEmpty($coreAuth->getCredentials()),
        ]);
    }

    public static function toCorePushNotificationConfig(\stdClass $compatConfig): TaskPushNotificationConfig
    {
        $core = new TaskPushNotificationConfig(['url' => self::str($compatConfig->url ?? null) ?? '']);
        if (($id = self::str($compatConfig->id ?? null)) !== null && $id !== '') {
            $core->setId($id);
        }
        if (($token = self::str($compatConfig->token ?? null)) !== null && $token !== '') {
            $core->setToken($token);
        }
        if (($auth = self::obj($compatConfig->authentication ?? null)) !== null) {
            $core->setAuthentication(self::toCoreAuthenticationInfo($auth));
        }

        return $core;
    }

    public static function toCompatPushNotificationConfig(TaskPushNotificationConfig $coreConfig): \stdClass
    {
        return self::compact([
            'url' => $coreConfig->getUrl(),
            'id' => self::nonEmpty($coreConfig->getId()),
            'token' => self::nonEmpty($coreConfig->getToken()),
            'authentication' => $coreConfig->hasAuthentication() && $coreConfig->getAuthentication() !== null
                ? self::toCompatAuthenticationInfo($coreConfig->getAuthentication())
                : null,
        ]);
    }

    public static function toCoreTaskPushNotificationConfig(\stdClass $compatConfig): TaskPushNotificationConfig
    {
        $core = new TaskPushNotificationConfig();
        if (($inner = self::obj($compatConfig->pushNotificationConfig ?? null)) !== null) {
            $core = self::toCorePushNotificationConfig($inner);
        }
        $core->setTaskId(self::str($compatConfig->taskId ?? null) ?? '');

        return $core;
    }

    public static function toCompatTaskPushNotificationConfig(TaskPushNotificationConfig $coreConfig): \stdClass
    {
        return (object) [
            'taskId' => $coreConfig->getTaskId(),
            'pushNotificationConfig' => self::toCompatPushNotificationConfig($coreConfig),
        ];
    }

    public static function toCoreSendMessageConfiguration(\stdClass $compatConfig): SendMessageConfiguration
    {
        // Blocking by default (return_immediately = false), as in Python.
        $core = new SendMessageConfiguration();
        $modes = self::strList($compatConfig->acceptedOutputModes ?? null);
        if ($modes !== []) {
            $core->setAcceptedOutputModes($modes);
        }
        if (($push = self::obj($compatConfig->pushNotificationConfig ?? null)) !== null) {
            $core->setTaskPushNotificationConfig(self::toCorePushNotificationConfig($push));
        }
        if (isset($compatConfig->historyLength) && is_int($compatConfig->historyLength)) {
            $core->setHistoryLength($compatConfig->historyLength);
        }
        if (isset($compatConfig->blocking) && is_bool($compatConfig->blocking)) {
            $core->setReturnImmediately(!$compatConfig->blocking);
        }

        return $core;
    }

    public static function toCompatSendMessageConfiguration(SendMessageConfiguration $coreConfig): \stdClass
    {
        return self::compact([
            'acceptedOutputModes' => self::listOrNull($coreConfig->getAcceptedOutputModes()),
            'pushNotificationConfig' => $coreConfig->hasTaskPushNotificationConfig() && $coreConfig->getTaskPushNotificationConfig() !== null
                ? self::toCompatPushNotificationConfig($coreConfig->getTaskPushNotificationConfig())
                : null,
            'historyLength' => $coreConfig->hasHistoryLength() ? $coreConfig->getHistoryLength() : null,
            'blocking' => !$coreConfig->getReturnImmediately(),
        ]);
    }

    // --- Artifacts and stream events ----------------------------------------

    public static function toCoreArtifact(\stdClass $compatArtifact): Artifact
    {
        $core = new Artifact(['artifact_id' => self::str($compatArtifact->artifactId ?? null) ?? '']);
        if (($name = self::str($compatArtifact->name ?? null)) !== null && $name !== '') {
            $core->setName($name);
        }
        if (($description = self::str($compatArtifact->description ?? null)) !== null && $description !== '') {
            $core->setDescription($description);
        }
        $parts = [];
        foreach (self::objList($compatArtifact->parts ?? null) as $part) {
            $parts[] = self::toCorePart($part);
        }
        $core->setParts($parts);
        if (($metadata = self::obj($compatArtifact->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }
        $extensions = self::strList($compatArtifact->extensions ?? null);
        if ($extensions !== []) {
            $core->setExtensions($extensions);
        }

        return $core;
    }

    public static function toCompatArtifact(Artifact $coreArtifact): \stdClass
    {
        $parts = [];
        foreach ($coreArtifact->getParts() as $part) {
            $parts[] = self::toCompatPart($part);
        }

        return self::compact([
            'artifactId' => $coreArtifact->getArtifactId(),
            'name' => self::nonEmpty($coreArtifact->getName()),
            'description' => self::nonEmpty($coreArtifact->getDescription()),
            'parts' => $parts,
            'metadata' => $coreArtifact->hasMetadata() && $coreArtifact->getMetadata() !== null ? self::fromStruct($coreArtifact->getMetadata()) : null,
            'extensions' => self::listOrNull($coreArtifact->getExtensions()),
        ]);
    }

    public static function toCoreTaskStatusUpdateEvent(\stdClass $compatEvent): TaskStatusUpdateEvent
    {
        $core = new TaskStatusUpdateEvent([
            'task_id' => self::str($compatEvent->taskId ?? null) ?? '',
            'context_id' => self::str($compatEvent->contextId ?? null) ?? '',
        ]);
        if (($status = self::obj($compatEvent->status ?? null)) !== null) {
            $core->setStatus(self::toCoreTaskStatus($status));
        }
        if (($metadata = self::obj($compatEvent->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }

        return $core;
    }

    public static function toCompatTaskStatusUpdateEvent(TaskStatusUpdateEvent $coreEvent): \stdClass
    {
        $status = $coreEvent->hasStatus() && $coreEvent->getStatus() !== null
            ? self::toCompatTaskStatus($coreEvent->getStatus())
            : (object) ['state' => 'unknown'];

        return self::compact([
            'kind' => 'status-update',
            'taskId' => $coreEvent->getTaskId(),
            'contextId' => $coreEvent->getContextId(),
            'status' => $status,
            'metadata' => $coreEvent->hasMetadata() && $coreEvent->getMetadata() !== null ? self::fromStruct($coreEvent->getMetadata()) : null,
            // v0.3 flagged the last event; v1.0 derives it from the state.
            'final' => in_array($status->state ?? null, self::TERMINAL_COMPAT_STATES, true),
        ]);
    }

    public static function toCoreTaskArtifactUpdateEvent(\stdClass $compatEvent): TaskArtifactUpdateEvent
    {
        $core = new TaskArtifactUpdateEvent([
            'task_id' => self::str($compatEvent->taskId ?? null) ?? '',
            'context_id' => self::str($compatEvent->contextId ?? null) ?? '',
        ]);
        if (($artifact = self::obj($compatEvent->artifact ?? null)) !== null) {
            $core->setArtifact(self::toCoreArtifact($artifact));
        }
        if (isset($compatEvent->append) && is_bool($compatEvent->append)) {
            $core->setAppend($compatEvent->append);
        }
        if (isset($compatEvent->lastChunk) && is_bool($compatEvent->lastChunk)) {
            $core->setLastChunk($compatEvent->lastChunk);
        }
        if (($metadata = self::obj($compatEvent->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }

        return $core;
    }

    public static function toCompatTaskArtifactUpdateEvent(TaskArtifactUpdateEvent $coreEvent): \stdClass
    {
        return self::compact([
            'kind' => 'artifact-update',
            'taskId' => $coreEvent->getTaskId(),
            'contextId' => $coreEvent->getContextId(),
            'artifact' => self::toCompatArtifact($coreEvent->getArtifact() ?? new Artifact()),
            'append' => $coreEvent->getAppend(),
            'lastChunk' => $coreEvent->getLastChunk(),
            'metadata' => $coreEvent->hasMetadata() && $coreEvent->getMetadata() !== null ? self::fromStruct($coreEvent->getMetadata()) : null,
        ]);
    }

    // --- Security ------------------------------------------------------------

    /**
     * @param \stdClass $compatReq v0.3 `{scheme: [scopes]}`
     */
    public static function toCoreSecurityRequirement(\stdClass $compatReq): SecurityRequirement
    {
        $core = new SecurityRequirement();
        $schemes = [];
        foreach (get_object_vars($compatReq) as $name => $scopes) {
            $schemes[(string) $name] = new StringList(['list' => self::strList($scopes)]);
        }
        $core->setSchemes($schemes);

        return $core;
    }

    public static function toCompatSecurityRequirement(SecurityRequirement $coreReq): \stdClass
    {
        $result = new \stdClass();
        foreach ($coreReq->getSchemes() as $name => $list) {
            $result->{self::key($name)} = $list instanceof StringList ? iterator_to_array($list->getList(), false) : [];
        }

        return $result;
    }

    public static function toCoreOauthFlows(\stdClass $compatFlows): OAuthFlows
    {
        $core = new OAuthFlows();
        if (($flow = self::obj($compatFlows->authorizationCode ?? null)) !== null) {
            $core->setAuthorizationCode(new AuthorizationCodeOAuthFlow(array_filter([
                'authorization_url' => self::str($flow->authorizationUrl ?? null) ?? '',
                'token_url' => self::str($flow->tokenUrl ?? null) ?? '',
                'scopes' => self::strMap($flow->scopes ?? null),
                'refresh_url' => self::str($flow->refreshUrl ?? null),
            ], static fn(mixed $v): bool => $v !== null)));
        }
        if (($flow = self::obj($compatFlows->clientCredentials ?? null)) !== null) {
            $core->setClientCredentials(new ClientCredentialsOAuthFlow(array_filter([
                'token_url' => self::str($flow->tokenUrl ?? null) ?? '',
                'scopes' => self::strMap($flow->scopes ?? null),
                'refresh_url' => self::str($flow->refreshUrl ?? null),
            ], static fn(mixed $v): bool => $v !== null)));
        }
        if (($flow = self::obj($compatFlows->implicit ?? null)) !== null) {
            $core->setImplicit(new ImplicitOAuthFlow(array_filter([
                'authorization_url' => self::str($flow->authorizationUrl ?? null) ?? '',
                'scopes' => self::strMap($flow->scopes ?? null),
                'refresh_url' => self::str($flow->refreshUrl ?? null),
            ], static fn(mixed $v): bool => $v !== null)));
        }
        if (($flow = self::obj($compatFlows->password ?? null)) !== null) {
            $core->setPassword(new PasswordOAuthFlow(array_filter([
                'token_url' => self::str($flow->tokenUrl ?? null) ?? '',
                'scopes' => self::strMap($flow->scopes ?? null),
                'refresh_url' => self::str($flow->refreshUrl ?? null),
            ], static fn(mixed $v): bool => $v !== null)));
        }

        return $core;
    }

    public static function toCompatOauthFlows(OAuthFlows $coreFlows): \stdClass
    {
        $scopes = static fn(iterable $s): \stdClass => (object) iterator_to_array($s);

        // device_code has no v0.3 equivalent and is dropped (Python too).
        return self::compact(match ($coreFlows->getFlow()) {
            'authorization_code' => ['authorizationCode' => self::compact([
                'authorizationUrl' => $coreFlows->getAuthorizationCode()?->getAuthorizationUrl() ?? '',
                'tokenUrl' => $coreFlows->getAuthorizationCode()?->getTokenUrl() ?? '',
                'scopes' => $scopes($coreFlows->getAuthorizationCode()?->getScopes() ?? []),
                'refreshUrl' => self::nonEmpty($coreFlows->getAuthorizationCode()?->getRefreshUrl() ?? ''),
            ])],
            'client_credentials' => ['clientCredentials' => self::compact([
                'tokenUrl' => $coreFlows->getClientCredentials()?->getTokenUrl() ?? '',
                'scopes' => $scopes($coreFlows->getClientCredentials()?->getScopes() ?? []),
                'refreshUrl' => self::nonEmpty($coreFlows->getClientCredentials()?->getRefreshUrl() ?? ''),
            ])],
            'implicit' => ['implicit' => self::compact([
                'authorizationUrl' => $coreFlows->getImplicit()?->getAuthorizationUrl() ?? '',
                'scopes' => $scopes($coreFlows->getImplicit()?->getScopes() ?? []),
                'refreshUrl' => self::nonEmpty($coreFlows->getImplicit()?->getRefreshUrl() ?? ''),
            ])],
            'password' => ['password' => self::compact([
                'tokenUrl' => $coreFlows->getPassword()?->getTokenUrl() ?? '',
                'scopes' => $scopes($coreFlows->getPassword()?->getScopes() ?? []),
                'refreshUrl' => self::nonEmpty($coreFlows->getPassword()?->getRefreshUrl() ?? ''),
            ])],
            default => [],
        });
    }

    public static function toCoreSecurityScheme(\stdClass $compatScheme): SecurityScheme
    {
        $core = new SecurityScheme();
        $description = self::str($compatScheme->description ?? null);
        switch (self::str($compatScheme->type ?? null)) {
            case 'apiKey':
                $core->setApiKeySecurityScheme(new APIKeySecurityScheme(array_filter([
                    'location' => self::str($compatScheme->in ?? null) ?? '',
                    'name' => self::str($compatScheme->name ?? null) ?? '',
                    'description' => $description,
                ], static fn(mixed $v): bool => $v !== null)));

                break;

            case 'http':
                $core->setHttpAuthSecurityScheme(new HTTPAuthSecurityScheme(array_filter([
                    'scheme' => self::str($compatScheme->scheme ?? null) ?? '',
                    'bearer_format' => self::str($compatScheme->bearerFormat ?? null),
                    'description' => $description,
                ], static fn(mixed $v): bool => $v !== null)));

                break;

            case 'oauth2':
                $core->setOauth2SecurityScheme(new OAuth2SecurityScheme(array_filter([
                    'flows' => self::toCoreOauthFlows(self::obj($compatScheme->flows ?? null) ?? new \stdClass()),
                    'oauth2_metadata_url' => self::str($compatScheme->oauth2MetadataUrl ?? null),
                    'description' => $description,
                ], static fn(mixed $v): bool => $v !== null)));

                break;

            case 'openIdConnect':
                $core->setOpenIdConnectSecurityScheme(new OpenIdConnectSecurityScheme(array_filter([
                    'open_id_connect_url' => self::str($compatScheme->openIdConnectUrl ?? null) ?? '',
                    'description' => $description,
                ], static fn(mixed $v): bool => $v !== null)));

                break;

            case 'mutualTLS':
                $core->setMtlsSecurityScheme(new MutualTlsSecurityScheme(array_filter([
                    'description' => $description,
                ], static fn(mixed $v): bool => $v !== null)));

                break;
        }

        return $core;
    }

    public static function toCompatSecurityScheme(SecurityScheme $coreScheme): \stdClass
    {
        switch ($coreScheme->getScheme()) {
            case 'api_key_security_scheme':
                $s = $coreScheme->getApiKeySecurityScheme() ?? new APIKeySecurityScheme();

                return self::compact(['type' => 'apiKey', 'in' => $s->getLocation(), 'name' => $s->getName(), 'description' => self::nonEmpty($s->getDescription())]);

            case 'http_auth_security_scheme':
                $s = $coreScheme->getHttpAuthSecurityScheme() ?? new HTTPAuthSecurityScheme();

                return self::compact(['type' => 'http', 'scheme' => $s->getScheme(), 'bearerFormat' => self::nonEmpty($s->getBearerFormat()), 'description' => self::nonEmpty($s->getDescription())]);

            case 'oauth2_security_scheme':
                $s = $coreScheme->getOauth2SecurityScheme() ?? new OAuth2SecurityScheme();

                return self::compact([
                    'type' => 'oauth2',
                    'flows' => self::toCompatOauthFlows($s->getFlows() ?? new OAuthFlows()),
                    'oauth2MetadataUrl' => self::nonEmpty($s->getOauth2MetadataUrl()),
                    'description' => self::nonEmpty($s->getDescription()),
                ]);

            case 'open_id_connect_security_scheme':
                $s = $coreScheme->getOpenIdConnectSecurityScheme() ?? new OpenIdConnectSecurityScheme();

                return self::compact(['type' => 'openIdConnect', 'openIdConnectUrl' => $s->getOpenIdConnectUrl(), 'description' => self::nonEmpty($s->getDescription())]);

            case 'mtls_security_scheme':
                $s = $coreScheme->getMtlsSecurityScheme() ?? new MutualTlsSecurityScheme();

                return self::compact(['type' => 'mutualTLS', 'description' => self::nonEmpty($s->getDescription())]);
        }

        throw new \ValueError('Unknown security scheme type: ' . ($coreScheme->getScheme() === '' ? 'none' : $coreScheme->getScheme()));
    }

    // --- Agent Card -----------------------------------------------------------

    public static function toCoreAgentInterface(\stdClass $compatInterface): AgentInterface
    {
        return new AgentInterface([
            'url' => self::str($compatInterface->url ?? null) ?? '',
            'protocol_binding' => self::str($compatInterface->transport ?? null) ?? '',
            'protocol_version' => Constants::PROTOCOL_VERSION_0_3,
        ]);
    }

    public static function toCompatAgentInterface(AgentInterface $coreInterface): \stdClass
    {
        return (object) ['url' => $coreInterface->getUrl(), 'transport' => $coreInterface->getProtocolBinding()];
    }

    public static function toCoreAgentProvider(\stdClass $compatProvider): AgentProvider
    {
        return new AgentProvider(['url' => self::str($compatProvider->url ?? null) ?? '', 'organization' => self::str($compatProvider->organization ?? null) ?? '']);
    }

    public static function toCompatAgentProvider(AgentProvider $coreProvider): \stdClass
    {
        return (object) ['url' => $coreProvider->getUrl(), 'organization' => $coreProvider->getOrganization()];
    }

    public static function toCoreAgentExtension(\stdClass $compatExt): AgentExtension
    {
        $core = new AgentExtension();
        if (($uri = self::str($compatExt->uri ?? null)) !== null && $uri !== '') {
            $core->setUri($uri);
        }
        if (($description = self::str($compatExt->description ?? null)) !== null && $description !== '') {
            $core->setDescription($description);
        }
        if (isset($compatExt->required) && is_bool($compatExt->required)) {
            $core->setRequired($compatExt->required);
        }
        if (($params = self::obj($compatExt->params ?? null)) !== null && get_object_vars($params) !== []) {
            $core->setParams(self::toStruct($params));
        }

        return $core;
    }

    public static function toCompatAgentExtension(AgentExtension $coreExt): \stdClass
    {
        return self::compact([
            'uri' => $coreExt->getUri(),
            'description' => self::nonEmpty($coreExt->getDescription()),
            'required' => $coreExt->getRequired(),
            'params' => $coreExt->hasParams() && $coreExt->getParams() !== null ? self::fromStruct($coreExt->getParams()) : null,
        ]);
    }

    public static function toCoreAgentCapabilities(\stdClass $compatCap): AgentCapabilities
    {
        $core = new AgentCapabilities();
        if (isset($compatCap->streaming) && is_bool($compatCap->streaming)) {
            $core->setStreaming($compatCap->streaming);
        }
        if (isset($compatCap->pushNotifications) && is_bool($compatCap->pushNotifications)) {
            $core->setPushNotifications($compatCap->pushNotifications);
        }
        $extensions = [];
        foreach (self::objList($compatCap->extensions ?? null) as $extension) {
            $extensions[] = self::toCoreAgentExtension($extension);
        }
        $core->setExtensions($extensions);

        return $core;
    }

    public static function toCompatAgentCapabilities(AgentCapabilities $coreCap): \stdClass
    {
        $extensions = [];
        foreach ($coreCap->getExtensions() as $extension) {
            $extensions[] = self::toCompatAgentExtension($extension);
        }

        return self::compact([
            'streaming' => $coreCap->hasStreaming() ? $coreCap->getStreaming() : null,
            'pushNotifications' => $coreCap->hasPushNotifications() ? $coreCap->getPushNotifications() : null,
            'extensions' => $extensions === [] ? null : $extensions,
            // stateTransitionHistory is gone in v1.0 and left out.
        ]);
    }

    public static function toCoreAgentSkill(\stdClass $compatSkill): AgentSkill
    {
        $security = [];
        foreach (self::objList($compatSkill->security ?? null) as $requirement) {
            $security[] = self::toCoreSecurityRequirement($requirement);
        }

        return new AgentSkill([
            'id' => self::str($compatSkill->id ?? null) ?? '',
            'name' => self::str($compatSkill->name ?? null) ?? '',
            'description' => self::str($compatSkill->description ?? null) ?? '',
            'tags' => self::strList($compatSkill->tags ?? null),
            'examples' => self::strList($compatSkill->examples ?? null),
            'input_modes' => self::strList($compatSkill->inputModes ?? null),
            'output_modes' => self::strList($compatSkill->outputModes ?? null),
            'security_requirements' => $security,
        ]);
    }

    public static function toCompatAgentSkill(AgentSkill $coreSkill): \stdClass
    {
        $security = [];
        foreach ($coreSkill->getSecurityRequirements() as $requirement) {
            $security[] = self::toCompatSecurityRequirement($requirement);
        }

        return self::compact([
            'id' => $coreSkill->getId(),
            'name' => $coreSkill->getName(),
            'description' => $coreSkill->getDescription(),
            'tags' => iterator_to_array($coreSkill->getTags(), false),
            'examples' => self::listOrNull($coreSkill->getExamples()),
            'inputModes' => self::listOrNull($coreSkill->getInputModes()),
            'outputModes' => self::listOrNull($coreSkill->getOutputModes()),
            'security' => $security === [] ? null : $security,
        ]);
    }

    public static function toCoreAgentCardSignature(\stdClass $compatSig): AgentCardSignature
    {
        $core = new AgentCardSignature([
            'protected' => self::str($compatSig->protected ?? null) ?? '',
            'signature' => self::str($compatSig->signature ?? null) ?? '',
        ]);
        if (($header = self::obj($compatSig->header ?? null)) !== null && get_object_vars($header) !== []) {
            $core->setHeader(self::toStruct($header));
        }

        return $core;
    }

    public static function toCompatAgentCardSignature(AgentCardSignature $coreSig): \stdClass
    {
        return self::compact([
            'protected' => $coreSig->getProtected(),
            'signature' => $coreSig->getSignature(),
            'header' => $coreSig->hasHeader() && $coreSig->getHeader() !== null ? self::fromStruct($coreSig->getHeader()) : null,
        ]);
    }

    public static function toCoreAgentCard(\stdClass $compatCard): AgentCard
    {
        $core = new AgentCard([
            'name' => self::str($compatCard->name ?? null) ?? '',
            'description' => self::str($compatCard->description ?? null) ?? '',
            'version' => self::str($compatCard->version ?? null) ?? '',
        ]);

        $interfaces = [new AgentInterface([
            'url' => self::str($compatCard->url ?? null) ?? '',
            'protocol_binding' => self::str($compatCard->preferredTransport ?? null) ?? 'JSONRPC',
            'protocol_version' => self::str($compatCard->protocolVersion ?? null) ?? Constants::PROTOCOL_VERSION_0_3,
        ])];
        foreach (self::objList($compatCard->additionalInterfaces ?? null) as $interface) {
            $interfaces[] = self::toCoreAgentInterface($interface);
        }
        $core->setSupportedInterfaces($interfaces);

        if (($provider = self::obj($compatCard->provider ?? null)) !== null) {
            $core->setProvider(self::toCoreAgentProvider($provider));
        }
        if (($documentationUrl = self::str($compatCard->documentationUrl ?? null)) !== null && $documentationUrl !== '') {
            $core->setDocumentationUrl($documentationUrl);
        }
        if (($iconUrl = self::str($compatCard->iconUrl ?? null)) !== null && $iconUrl !== '') {
            $core->setIconUrl($iconUrl);
        }

        $capabilities = self::toCoreAgentCapabilities(self::obj($compatCard->capabilities ?? null) ?? new \stdClass());
        if (isset($compatCard->supportsAuthenticatedExtendedCard) && is_bool($compatCard->supportsAuthenticatedExtendedCard)) {
            $capabilities->setExtendedAgentCard($compatCard->supportsAuthenticatedExtendedCard);
        }
        $core->setCapabilities($capabilities);

        $schemes = [];
        foreach (get_object_vars(self::obj($compatCard->securitySchemes ?? null) ?? new \stdClass()) as $name => $scheme) {
            if ($scheme instanceof \stdClass) {
                $schemes[(string) $name] = self::toCoreSecurityScheme($scheme);
            }
        }
        $core->setSecuritySchemes($schemes);

        $security = [];
        foreach (self::objList($compatCard->security ?? null) as $requirement) {
            $security[] = self::toCoreSecurityRequirement($requirement);
        }
        $core->setSecurityRequirements($security);

        $core->setDefaultInputModes(self::strList($compatCard->defaultInputModes ?? null));
        $core->setDefaultOutputModes(self::strList($compatCard->defaultOutputModes ?? null));

        $skills = [];
        foreach (self::objList($compatCard->skills ?? null) as $skill) {
            $skills[] = self::toCoreAgentSkill($skill);
        }
        $core->setSkills($skills);

        $signatures = [];
        foreach (self::objList($compatCard->signatures ?? null) as $signature) {
            $signatures[] = self::toCoreAgentCardSignature($signature);
        }
        $core->setSignatures($signatures);

        return $core;
    }

    /**
     * The v0.3 view of a v1.0 card: the first v0.3 (or unversioned)
     * interface becomes `url` / `preferredTransport`, the rest
     * `additionalInterfaces`.
     *
     * @throws VersionNotSupportedError when the card offers no v0.3 interface
     */
    public static function toCompatAgentCard(AgentCard $coreCard): \stdClass
    {
        $compatInterfaces = [];
        foreach ($coreCard->getSupportedInterfaces() as $interface) {
            if ($interface->getProtocolVersion() === '' || Versions::isLegacyVersion($interface->getProtocolVersion())) {
                $compatInterfaces[] = $interface;
            }
        }
        if ($compatInterfaces === []) {
            throw new VersionNotSupportedError('AgentCard must have at least one interface with compatible protocol version.');
        }
        $primary = array_shift($compatInterfaces);

        $capabilities = $coreCard->getCapabilities() ?? new AgentCapabilities();
        $schemes = [];
        foreach ($coreCard->getSecuritySchemes() as $name => $scheme) {
            if ($scheme instanceof SecurityScheme) {
                $schemes[self::key($name)] = self::toCompatSecurityScheme($scheme);
            }
        }
        $security = [];
        foreach ($coreCard->getSecurityRequirements() as $requirement) {
            $security[] = self::toCompatSecurityRequirement($requirement);
        }
        $skills = [];
        foreach ($coreCard->getSkills() as $skill) {
            $skills[] = self::toCompatAgentSkill($skill);
        }
        $signatures = [];
        foreach ($coreCard->getSignatures() as $signature) {
            $signatures[] = self::toCompatAgentCardSignature($signature);
        }

        return self::compact([
            'name' => $coreCard->getName(),
            'description' => $coreCard->getDescription(),
            'version' => $coreCard->getVersion(),
            'url' => $primary->getUrl(),
            'preferredTransport' => $primary->getProtocolBinding(),
            'protocolVersion' => $primary->getProtocolVersion() !== '' ? $primary->getProtocolVersion() : Constants::PROTOCOL_VERSION_0_3,
            'additionalInterfaces' => $compatInterfaces === [] ? null : array_map(self::toCompatAgentInterface(...), $compatInterfaces),
            'provider' => $coreCard->hasProvider() && $coreCard->getProvider() !== null ? self::toCompatAgentProvider($coreCard->getProvider()) : null,
            'documentationUrl' => $coreCard->hasDocumentationUrl() ? $coreCard->getDocumentationUrl() : null,
            'iconUrl' => $coreCard->hasIconUrl() ? $coreCard->getIconUrl() : null,
            'capabilities' => self::toCompatAgentCapabilities($capabilities),
            'supportsAuthenticatedExtendedCard' => $capabilities->hasExtendedAgentCard() ? $capabilities->getExtendedAgentCard() : null,
            'securitySchemes' => $schemes === [] ? null : (object) $schemes,
            'security' => $security === [] ? null : $security,
            'defaultInputModes' => iterator_to_array($coreCard->getDefaultInputModes(), false),
            'defaultOutputModes' => iterator_to_array($coreCard->getDefaultOutputModes(), false),
            'skills' => $skills,
            'signatures' => $signatures === [] ? null : $signatures,
        ]);
    }

    // --- Requests and responses (JSON-RPC `params` / `result`) ---------------

    public static function toCoreSendMessageRequest(\stdClass $params): SendMessageRequest
    {
        $core = new SendMessageRequest();
        if (($message = self::obj($params->message ?? null)) !== null) {
            $core->setMessage(self::toCoreMessage($message));
        }
        if (($configuration = self::obj($params->configuration ?? null)) !== null) {
            $core->setConfiguration(self::toCoreSendMessageConfiguration($configuration));
        }
        if (($metadata = self::obj($params->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }

        return $core;
    }

    public static function toCompatSendMessageRequest(SendMessageRequest $coreReq): \stdClass
    {
        return self::compact([
            'message' => self::toCompatMessage($coreReq->getMessage() ?? new Message()),
            'configuration' => $coreReq->hasConfiguration() && $coreReq->getConfiguration() !== null
                ? self::toCompatSendMessageConfiguration($coreReq->getConfiguration())
                : null,
            'metadata' => $coreReq->hasMetadata() && $coreReq->getMetadata() !== null ? self::fromStruct($coreReq->getMetadata()) : null,
        ]);
    }

    public static function toCoreGetTaskRequest(\stdClass $params): GetTaskRequest
    {
        $core = new GetTaskRequest(['id' => self::str($params->id ?? null) ?? '']);
        if (isset($params->historyLength) && is_int($params->historyLength)) {
            $core->setHistoryLength($params->historyLength);
        }

        return $core;
    }

    public static function toCompatGetTaskRequest(GetTaskRequest $coreReq): \stdClass
    {
        return self::compact(['id' => $coreReq->getId(), 'historyLength' => $coreReq->hasHistoryLength() ? $coreReq->getHistoryLength() : null]);
    }

    public static function toCoreCancelTaskRequest(\stdClass $params): CancelTaskRequest
    {
        $core = new CancelTaskRequest(['id' => self::str($params->id ?? null) ?? '']);
        if (($metadata = self::obj($params->metadata ?? null)) !== null && get_object_vars($metadata) !== []) {
            $core->setMetadata(self::toStruct($metadata));
        }

        return $core;
    }

    public static function toCompatCancelTaskRequest(CancelTaskRequest $coreReq): \stdClass
    {
        return self::compact([
            'id' => $coreReq->getId(),
            'metadata' => $coreReq->hasMetadata() && $coreReq->getMetadata() !== null ? self::fromStruct($coreReq->getMetadata()) : null,
        ]);
    }

    /**
     * Params are GetTaskPushNotificationConfigParams (id +
     * pushNotificationConfigId) or plain TaskIdParams.
     */
    public static function toCoreGetTaskPushNotificationConfigRequest(\stdClass $params): GetTaskPushNotificationConfigRequest
    {
        return new GetTaskPushNotificationConfigRequest([
            'task_id' => self::str($params->id ?? null) ?? '',
            'id' => self::str($params->pushNotificationConfigId ?? null) ?? '',
        ]);
    }

    public static function toCompatGetTaskPushNotificationConfigRequest(GetTaskPushNotificationConfigRequest $coreReq): \stdClass
    {
        return $coreReq->getId() !== ''
            ? (object) ['id' => $coreReq->getTaskId(), 'pushNotificationConfigId' => $coreReq->getId()]
            : (object) ['id' => $coreReq->getTaskId()];
    }

    public static function toCoreDeleteTaskPushNotificationConfigRequest(\stdClass $params): DeleteTaskPushNotificationConfigRequest
    {
        return new DeleteTaskPushNotificationConfigRequest([
            'task_id' => self::str($params->id ?? null) ?? '',
            'id' => self::str($params->pushNotificationConfigId ?? null) ?? '',
        ]);
    }

    public static function toCompatDeleteTaskPushNotificationConfigRequest(DeleteTaskPushNotificationConfigRequest $coreReq): \stdClass
    {
        return (object) ['id' => $coreReq->getTaskId(), 'pushNotificationConfigId' => $coreReq->getId()];
    }

    public static function toCoreCreateTaskPushNotificationConfigRequest(\stdClass $params): TaskPushNotificationConfig
    {
        return self::toCoreTaskPushNotificationConfig($params);
    }

    public static function toCompatCreateTaskPushNotificationConfigRequest(TaskPushNotificationConfig $coreReq): \stdClass
    {
        return self::toCompatTaskPushNotificationConfig($coreReq);
    }

    public static function toCoreSubscribeToTaskRequest(\stdClass $params): SubscribeToTaskRequest
    {
        return new SubscribeToTaskRequest(['id' => self::str($params->id ?? null) ?? '']);
    }

    public static function toCompatSubscribeToTaskRequest(SubscribeToTaskRequest $coreReq): \stdClass
    {
        return (object) ['id' => $coreReq->getId()];
    }

    public static function toCoreListTaskPushNotificationConfigRequest(\stdClass $params): ListTaskPushNotificationConfigsRequest
    {
        $core = new ListTaskPushNotificationConfigsRequest();
        if (($id = self::str($params->id ?? null)) !== null && $id !== '') {
            $core->setTaskId($id);
        }

        return $core;
    }

    public static function toCompatListTaskPushNotificationConfigRequest(ListTaskPushNotificationConfigsRequest $coreReq): \stdClass
    {
        return (object) ['id' => $coreReq->getTaskId()];
    }

    /**
     * @param list<\stdClass>|mixed $result the v0.3 `result`: a list of TaskPushNotificationConfig
     */
    public static function toCoreListTaskPushNotificationConfigResponse(mixed $result): ListTaskPushNotificationConfigsResponse
    {
        $configs = [];
        foreach (self::objList($result) as $config) {
            $configs[] = self::toCoreTaskPushNotificationConfig($config);
        }

        return new ListTaskPushNotificationConfigsResponse(['configs' => $configs]);
    }

    /**
     * @return list<\stdClass>
     */
    public static function toCompatListTaskPushNotificationConfigResponse(ListTaskPushNotificationConfigsResponse $coreRes): array
    {
        $result = [];
        foreach ($coreRes->getConfigs() as $config) {
            $result[] = self::toCompatTaskPushNotificationConfig($config);
        }

        return $result;
    }

    /**
     * @param \stdClass $result a v0.3 Task or Message
     */
    public static function toCoreSendMessageResponse(\stdClass $result): SendMessageResponse
    {
        return match (self::resultKind($result)) {
            'task' => new SendMessageResponse(['task' => self::toCoreTask($result)]),
            'message' => new SendMessageResponse(['message' => self::toCoreMessage($result)]),
            default => new SendMessageResponse(),
        };
    }

    public static function toCompatSendMessageResponse(SendMessageResponse $coreRes): \stdClass
    {
        return $coreRes->hasTask() && $coreRes->getTask() !== null
            ? self::toCompatTask($coreRes->getTask())
            : self::toCompatMessage($coreRes->getMessage() ?? new Message());
    }

    /**
     * @param \stdClass $result a v0.3 streaming `result` (Task, Message or an update event)
     */
    public static function toCoreStreamResponse(\stdClass $result): StreamResponse
    {
        return match (self::resultKind($result)) {
            'message' => new StreamResponse(['message' => self::toCoreMessage($result)]),
            'task' => new StreamResponse(['task' => self::toCoreTask($result)]),
            'status-update' => new StreamResponse(['status_update' => self::toCoreTaskStatusUpdateEvent($result)]),
            'artifact-update' => new StreamResponse(['artifact_update' => self::toCoreTaskArtifactUpdateEvent($result)]),
            default => new StreamResponse(),
        };
    }

    public static function toCompatStreamResponse(StreamResponse $coreRes): \stdClass
    {
        return match ($coreRes->getPayload()) {
            'message' => self::toCompatMessage($coreRes->getMessage() ?? new Message()),
            'task' => self::toCompatTask($coreRes->getTask() ?? new Task()),
            'status_update' => self::toCompatTaskStatusUpdateEvent($coreRes->getStatusUpdate() ?? new TaskStatusUpdateEvent()),
            'artifact_update' => self::toCompatTaskArtifactUpdateEvent($coreRes->getArtifactUpdate() ?? new TaskArtifactUpdateEvent()),
            default => throw new \ValueError('Unknown stream response event type: ' . ($coreRes->getPayload() === '' ? 'none' : $coreRes->getPayload())),
        };
    }

    public static function toCoreGetExtendedAgentCardRequest(\stdClass $params): GetExtendedAgentCardRequest
    {
        return new GetExtendedAgentCardRequest();
    }

    /**
     * The `kind` of a v0.3 result, inferred for old servers that leave it
     * out (Python's CompatJsonRpcTransport does the same).
     */
    public static function resultKind(\stdClass $result): ?string
    {
        $kind = self::str($result->kind ?? null);
        if ($kind !== null && $kind !== '') {
            return $kind;
        }
        if (isset($result->taskId, $result->final)) {
            return 'status-update';
        }
        if (isset($result->taskId, $result->artifact)) {
            return 'artifact-update';
        }
        if (isset($result->messageId)) {
            return 'message';
        }
        if (isset($result->id)) {
            return 'task';
        }

        return null;
    }

    // --- Helpers -----------------------------------------------------------------

    /**
     * A v0.3 Part's kind. v0.3 parts carry `kind`; the member name decides
     * when it is missing.
     */
    private static function kindOf(\stdClass $part): ?string
    {
        $kind = self::str($part->kind ?? null);
        if ($kind !== null && $kind !== '') {
            return $kind;
        }

        return match (true) {
            isset($part->text) => 'text',
            isset($part->file) => 'file',
            isset($part->data) => 'data',
            default => null,
        };
    }

    /**
     * An object from the array, without its null members (pydantic
     * exclude_none).
     *
     * @param array<string, mixed> $values
     */
    private static function compact(array $values): \stdClass
    {
        return (object) array_filter($values, static fn(mixed $value): bool => $value !== null);
    }

    private static function key(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }

    private static function obj(mixed $value): ?\stdClass
    {
        return $value instanceof \stdClass ? $value : null;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    private static function nonEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function strList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
    }

    /**
     * @return array<string, string>
     */
    private static function strMap(mixed $value): array
    {
        $result = [];
        foreach ($value instanceof \stdClass ? get_object_vars($value) : [] as $key => $v) {
            $result[(string) $key] = is_scalar($v) ? (string) $v : '';
        }

        return $result;
    }

    /**
     * @return list<\stdClass>
     */
    private static function objList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $v): bool => $v instanceof \stdClass));
    }

    /**
     * @param iterable<mixed> $values
     *
     * @return list<mixed>|null
     */
    private static function listOrNull(iterable $values): ?array
    {
        $list = [];
        foreach ($values as $value) {
            $list[] = $value;
        }

        return $list === [] ? null : $list;
    }

    private static function toStruct(\stdClass $value): Struct
    {
        $struct = new Struct();
        $struct->mergeFromJsonString(self::encode($value));

        return $struct;
    }

    private static function toValue(mixed $value): Value
    {
        $proto = new Value();
        $proto->mergeFromJsonString(self::encode($value));

        return $proto;
    }

    /**
     * A Struct as an object. The pure-PHP runtime writes an empty Struct as
     * `[]`, so that case is mapped back to `{}`.
     */
    private static function fromStruct(?Struct $struct): \stdClass
    {
        if ($struct === null) {
            return new \stdClass();
        }
        $value = json_decode($struct->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);

        return $value instanceof \stdClass ? $value : new \stdClass();
    }

    private static function fromValue(Value $value): mixed
    {
        if ($value->getKind() === 'struct_value') {
            return self::fromStruct($value->getStructValue() ?? new Struct());
        }

        return json_decode($value->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function toTimestamp(string $value): Timestamp
    {
        $timestamp = new Timestamp();
        try {
            $timestamp->mergeFromJsonString(self::encode(str_replace('+00:00', 'Z', $value)));

            return $timestamp;
        } catch (\Throwable) {
            // Any other ISO 8601 form (offsets, no fraction): normalise to UTC.
        }
        try {
            $timestamp->fromDateTime(new \DateTime((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')));
        } catch (\Throwable) {
            // Unparseable: leave the timestamp unset rather than failing the call.
        }

        return $timestamp;
    }

    private static function timestampToString(Timestamp $timestamp): string
    {
        $json = json_decode($timestamp->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);

        return is_string($json) ? $json : '';
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
