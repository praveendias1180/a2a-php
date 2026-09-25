<?php

declare(strict_types=1);

namespace A2A\Tests\Client\Errors;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;
use A2A\Client\Errors\AgentCardResolutionError;
use A2A\Utils\Errors\A2AError;
use PHPUnit\Framework\TestCase;

/**
 * Port of tests/client/test_errors.py
 */
final class ErrorsTest extends TestCase
{
    public function testInstantiation(): void
    {
        $error = new A2AClientError('Test error message');

        self::assertInstanceOf(\Exception::class, $error);
        self::assertInstanceOf(A2AError::class, $error);
        self::assertSame('Test error message', $error->getMessage());
    }

    public function testInheritance(): void
    {
        self::assertInstanceOf(\Exception::class, new A2AClientError());
        self::assertInstanceOf(A2AClientError::class, new A2AClientTimeoutError('t'));
        self::assertInstanceOf(A2AClientError::class, new AgentCardResolutionError('r'));
    }

    public function testRaisingBaseError(): void
    {
        $this->expectException(A2AClientError::class);
        $this->expectExceptionMessage('Generic client error');

        throw new A2AClientError('Generic client error');
    }

    public function testAgentCardResolutionErrorCarriesTheStatusCode(): void
    {
        $error = new AgentCardResolutionError('Failed', 404);

        self::assertSame(404, $error->statusCode);
        self::assertNull((new AgentCardResolutionError('Failed'))->statusCode);
    }
}
