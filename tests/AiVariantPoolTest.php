<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiVariantPool;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Ai\Support\FakeDatabase;

#[CoversClass(AiVariantPool::class)]
#[UsesClass(AiRequest::class)]
#[UsesClass(AiResponse::class)]
#[UsesClass(FinishReason::class)]
#[UsesClass(TokenUsage::class)]
final class AiVariantPoolTest extends TestCase
{
    private function makeClient(string ...$contents): AiClientInterface
    {
        $queue = array_values($contents);

        return new class ($queue) implements AiClientInterface {
            /** @param list<string> $queue */
            public function __construct(private array $queue)
            {
            }

            public function complete(AiRequest $request): AiResponse
            {
                $content = array_shift($this->queue) ?? '';

                return new AiResponse($content, FinishReason::STOP, new TokenUsage(5, 5), '{}');
            }
        };
    }

    public function testCountVariantsReturnsZeroWhenEmpty(): void
    {
        $db = new FakeDatabase();
        $db->addQueryResult([['cnt' => 0]]);

        $pool = new AiVariantPool($this->makeClient(), $db);

        self::assertSame(0, $pool->countVariants('key'));
    }

    public function testCountVariantsReturnsStoredCount(): void
    {
        $db = new FakeDatabase();
        $db->addQueryResult([['cnt' => 3]]);

        $pool = new AiVariantPool($this->makeClient(), $db);

        self::assertSame(3, $pool->countVariants('key'));
    }

    public function testGenerateBatchInsertsOneVariant(): void
    {
        $db = new FakeDatabase();
        $pool = new AiVariantPool($this->makeClient('A shining sword.'), $db);

        $pool->generateBatch('sword', AiRequest::make('Describe a sword.'), 1);

        $executed = $db->getExecuted();
        self::assertCount(1, $executed);
        self::assertStringContainsString('INSERT', strtoupper($executed[0]['sql']));
        self::assertContains('A shining sword.', $executed[0]['bindings']);
        self::assertContains('sword', $executed[0]['bindings']);
    }

    public function testGenerateBatchInsertsMultipleVariants(): void
    {
        $db = new FakeDatabase();
        $pool = new AiVariantPool($this->makeClient('V1', 'V2', 'V3'), $db);

        $pool->generateBatch('key', AiRequest::make('Generate.'), 3);

        self::assertCount(3, $db->getExecuted());
    }

    public function testGetVariantReturnsStoredContent(): void
    {
        $db = new FakeDatabase();
        $db->addQueryResult([['content' => 'A legendary blade.']]);

        $pool = new AiVariantPool($this->makeClient(), $db);
        $variant = $pool->getVariant('sword', AiRequest::make('Describe.'));

        self::assertSame('A legendary blade.', $variant);
    }

    public function testGetVariantTriggersGenerationOnMiss(): void
    {
        $db = new FakeDatabase();
        // First fetchVariants returns empty (miss), second returns the generated row
        $db->addQueryResult([]);
        $db->addQueryResult([['content' => 'Newly generated content.']]);

        $pool = new AiVariantPool($this->makeClient('Newly generated content.'), $db);
        $variant = $pool->getVariant('key', AiRequest::make('Generate.'));

        // One INSERT should have been executed
        self::assertCount(1, $db->getExecuted());
        self::assertSame('Newly generated content.', $variant);
    }

    public function testGetVariantReturnsEmptyStringWhenGenerationProducesNothing(): void
    {
        $db = new FakeDatabase();
        // Both fetch attempts return empty
        $db->addQueryResult([]);
        $db->addQueryResult([]);

        $pool = new AiVariantPool($this->makeClient(''), $db);
        $result = $pool->getVariant('key', AiRequest::make('Generate.'));

        self::assertSame('', $result);
    }
}
