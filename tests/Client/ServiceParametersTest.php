<?php

declare(strict_types=1);

namespace A2A\Tests\Client;

use A2A\Client\ServiceParameters;
use A2A\Client\ServiceParametersFactory;
use A2A\Extensions\Common;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_service_parameters.py
 */
final class ServiceParametersTest extends TestCase
{
    public function testWithA2aExtensionsMergesDedupesAndSorts(): void
    {
        $parameters = ServiceParametersFactory::create([
            ServiceParameters::withA2aExtensions(['ext-c', 'ext-a']),
            ServiceParameters::withA2aExtensions(['ext-b', 'ext-a']),
        ]);

        self::assertSame('ext-a,ext-b,ext-c', $parameters[Common::HTTP_EXTENSION_HEADER]);
    }

    public function testWithA2aExtensionsMergesExistingHeaderValue(): void
    {
        $parameters = ServiceParametersFactory::createFrom(
            [Common::HTTP_EXTENSION_HEADER => 'ext-a, ext-b'],
            [ServiceParameters::withA2aExtensions(['ext-c'])],
        );

        self::assertSame('ext-a,ext-b,ext-c', $parameters[Common::HTTP_EXTENSION_HEADER]);
    }

    public function testWithA2aExtensionsEmptyIsNoop(): void
    {
        $parameters = ServiceParametersFactory::create([
            ServiceParameters::withA2aExtensions(['ext-a']),
            ServiceParameters::withA2aExtensions([]),
        ]);

        self::assertSame('ext-a', $parameters[Common::HTTP_EXTENSION_HEADER]);
        self::assertArrayNotHasKey(Common::HTTP_EXTENSION_HEADER, ServiceParametersFactory::create([ServiceParameters::withA2aExtensions([])]));
    }

    public function testWithA2aExtensionsNormalizesInputStrings(): void
    {
        $parameters = ServiceParametersFactory::create([ServiceParameters::withA2aExtensions(['ext-a, ext-b', '  ext-c  '])]);

        self::assertSame('ext-a,ext-b,ext-c', $parameters[Common::HTTP_EXTENSION_HEADER]);
    }

    public function testCreateFromDoesNotChangeTheOriginal(): void
    {
        $original = ['X-Other' => 'keep'];

        $result = ServiceParametersFactory::createFrom($original, [ServiceParameters::withA2aExtensions(['ext-a'])]);

        self::assertSame(['X-Other' => 'keep'], $original);
        self::assertSame(['X-Other' => 'keep', Common::HTTP_EXTENSION_HEADER => 'ext-a'], $result);
    }
}
