<?php

declare(strict_types=1);

namespace A2A\Client;

/**
 * Builds service-parameter maps (sent as HTTP headers) from update closures.
 *
 * Mirrors a2a-python: ServiceParametersFactory in
 * src/a2a/client/service_parameters.py
 */
final class ServiceParametersFactory
{
    private function __construct() {}

    /**
     * @param list<callable(array<string, string>): array<string, string>> $updates
     *
     * @return array<string, string>
     */
    public static function create(array $updates): array
    {
        return self::createFrom(null, $updates);
    }

    /**
     * @param array<string, string>|null                                     $serviceParameters
     * @param list<callable(array<string, string>): array<string, string>> $updates
     *
     * @return array<string, string>
     */
    public static function createFrom(?array $serviceParameters, array $updates): array
    {
        $result = $serviceParameters ?? [];
        foreach ($updates as $update) {
            $result = $update($result);
        }

        return $result;
    }
}
