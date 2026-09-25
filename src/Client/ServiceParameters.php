<?php

declare(strict_types=1);

namespace A2A\Client;

use A2A\Extensions\Common;

/**
 * Service-parameter updates.
 *
 * Mirrors the module-level functions of a2a-python's
 * src/a2a/client/service_parameters.py. Python's updates mutate the dict in
 * place; PHP arrays are values, so an update returns the new array.
 */
final class ServiceParameters
{
    private function __construct() {}

    /**
     * An update that merges A2A extension URIs into the A2A-Extensions
     * parameter: union with what is there, de-duplicated, sorted. Repeated
     * updates accumulate.
     *
     * @param list<string> $extensions
     *
     * @return \Closure(array<string, string>): array<string, string>
     */
    public static function withA2aExtensions(array $extensions): \Closure
    {
        return static fn(array $parameters): array => self::mergeExtensions($parameters, $extensions);
    }

    /**
     * @param array<mixed> $parameters
     * @param list<string> $extensions
     *
     * @return array<string, string>
     */
    private static function mergeExtensions(array $parameters, array $extensions): array
    {
        $clean = [];
        foreach ($parameters as $name => $value) {
            if (is_string($value)) {
                $clean[(string) $name] = $value;
            }
        }
        $parameters = $clean;
        if ($extensions === []) {
            return $parameters;
        }
        $existing = $parameters[Common::HTTP_EXTENSION_HEADER] ?? '';
        $merged = Common::getRequestedExtensions([$existing, ...$extensions]);
        sort($merged, SORT_STRING);
        $parameters[Common::HTTP_EXTENSION_HEADER] = implode(',', $merged);

        return $parameters;
    }
}
