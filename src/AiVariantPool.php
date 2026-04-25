<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Request\AiRequest;
use EzPhp\Contracts\DatabaseInterface;

/**
 * A database-backed pool of AI-generated text variants for a given cache key.
 *
 * Variants are stored in a flat table with (cache_key, content, created_at) columns.
 * getVariant() picks a random variant; on a cache miss it triggers lazy generation
 * by calling the AI client once before returning.
 *
 * The table must exist before use. Recommended schema:
 *
 *   CREATE TABLE ai_variants (
 *       id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *       cache_key  VARCHAR(255) NOT NULL,
 *       content    TEXT         NOT NULL,
 *       created_at DATETIME     NOT NULL,
 *       INDEX idx_cache_key (cache_key)
 *   );
 *
 * @package EzPhp\Ai
 */
final class AiVariantPool
{
    /**
     * @param AiClientInterface $client
     * @param DatabaseInterface $db
     * @param string            $table Database table name.
     */
    public function __construct(
        private readonly AiClientInterface $client,
        private readonly DatabaseInterface $db,
        private readonly string $table = 'ai_variants',
    ) {
    }

    /**
     * Return a random variant for the given cache key.
     *
     * If no variants exist yet, one is generated and persisted before returning.
     * Returns an empty string only if generation produces an empty response.
     *
     * @param string    $cacheKey Identifies the pool (e.g. 'sword_description').
     * @param AiRequest $request  Used for lazy generation on a cache miss.
     *
     * @return string
     */
    public function getVariant(string $cacheKey, AiRequest $request): string
    {
        $variants = $this->fetchVariants($cacheKey);

        if ($variants === []) {
            $this->generateBatch($cacheKey, $request, 1);
            $variants = $this->fetchVariants($cacheKey);
        }

        if ($variants === []) {
            return '';
        }

        $variant = $variants[random_int(0, count($variants) - 1)];
        $content = $variant['content'] ?? '';

        return is_string($content) ? $content : '';
    }

    /**
     * Generate and persist $count new variants for the given cache key.
     *
     * Each variant is produced by a separate AI completion call.
     *
     * @param string    $cacheKey
     * @param AiRequest $request
     * @param int       $count    Number of variants to generate. Defaults to 5.
     *
     * @return void
     */
    public function generateBatch(string $cacheKey, AiRequest $request, int $count = 5): void
    {
        for ($i = 0; $i < $count; $i++) {
            $response = $this->client->complete($request);
            $this->db->execute(
                "INSERT INTO {$this->table} (cache_key, content, created_at) VALUES (?, ?, ?)",
                [$cacheKey, $response->content(), date('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * Return the number of stored variants for the given cache key.
     *
     * @param string $cacheKey
     *
     * @return int
     */
    public function countVariants(string $cacheKey): int
    {
        $rows = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE cache_key = ?",
            [$cacheKey],
        );

        $cnt = $rows[0]['cnt'] ?? 0;

        return is_numeric($cnt) ? (int) $cnt : 0;
    }

    /**
     * @param string $cacheKey
     *
     * @return list<array<string, mixed>>
     */
    private function fetchVariants(string $cacheKey): array
    {
        return $this->db->query(
            "SELECT content FROM {$this->table} WHERE cache_key = ?",
            [$cacheKey],
        );
    }
}
