<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\HostResolver;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\PublicHostGuard;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use PHPUnit\Framework\TestCase;

final class PublicHostGuardTest extends TestCase
{
    public function testRejectsPrivateAndLinkLocalAddresses(): void
    {
        $guard = $this->guard([]);

        $this->expectException(ImageRejectedException::class);
        $guard->assertPublicIp('10.1.2.3');
    }

    public function testRejectsAResolvedPrivateAddress(): void
    {
        $guard = $this->guard(['cdn.test' => ['192.168.0.5']]);

        $this->expectException(ImageRejectedException::class);
        $guard->safeAddresses('https://cdn.test/hero.jpg');
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $guard = $this->guard([]);

        $this->expectException(ImageRejectedException::class);
        $guard->safeAddresses('file:///etc/passwd');
    }

    public function testAcceptsAPublicAddress(): void
    {
        $guard = $this->guard(['cdn.test' => ['8.8.8.8']]);

        $this->assertSame(['8.8.8.8'], $guard->safeAddresses('https://cdn.test/hero.jpg'));
    }

    public function testAcceptsALiteralPublicAddressWithoutResolvingIt(): void
    {
        $guard = $this->guard([]);

        $this->assertSame(['8.8.8.8'], $guard->safeAddresses('https://8.8.8.8/hero.jpg'));
    }

    public function testRejectsCredentialsInTheUrl(): void
    {
        $guard = $this->guard(['cdn.test' => ['8.8.8.8']]);

        $this->assertRejection(
            Translation::ERROR_CREDENTIALS,
            static fn (): array => $guard->safeAddresses('https://user:secret@cdn.test/hero.jpg'),
        );
    }

    public function testRejectsAnUnresolvedHost(): void
    {
        $guard = $this->guard([]);

        $this->assertRejection(
            Translation::ERROR_RESOLVE,
            static fn (): array => $guard->safeAddresses('https://cdn.test/hero.jpg'),
        );
    }

    public function testRejectsALiteralLinkLocalAddress(): void
    {
        $guard = $this->guard([]);

        $this->assertRejection(
            Translation::ERROR_BLOCKED,
            static fn (): array => $guard->safeAddresses('http://169.254.1.1/hero.jpg'),
        );
    }

    /**
     * @param array<string, list<string>> $addresses
     */
    private function guard(array $addresses): PublicHostGuard
    {
        return new PublicHostGuard(new class($addresses) implements HostResolver {
            /** @param array<string, list<string>> $addresses */
            public function __construct(private array $addresses)
            {
            }

            public function resolve(string $host): array
            {
                return $this->addresses[$host] ?? [];
            }
        });
    }

    private function assertRejection(string $messageKey, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the address to be rejected.');
        } catch (ImageRejectedException $exception) {
            $this->assertSame($messageKey, $exception->getMessage());
        }
    }
}
