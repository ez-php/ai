<?php

declare(strict_types=1);

namespace Tests\Message;

use EzPhp\Ai\Message\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $names = array_map(static fn (Role $r) => $r->name, Role::cases());

        self::assertContains('USER', $names);
        self::assertContains('ASSISTANT', $names);
        self::assertContains('SYSTEM', $names);
        self::assertContains('TOOL', $names);
        self::assertCount(4, Role::cases());
    }

    /**
     * @param string $name
     * @param string $value
     */
    #[DataProvider('roleProvider')]
    public function testBackingValues(string $name, string $value): void
    {
        $role = Role::from($value);

        self::assertSame($name, $role->name);
        self::assertSame($value, $role->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roleProvider(): array
    {
        return [
            'user' => ['USER', 'user'],
            'assistant' => ['ASSISTANT', 'assistant'],
            'system' => ['SYSTEM', 'system'],
            'tool' => ['TOOL', 'tool'],
        ];
    }
}
