<?php

namespace App\AI\Retrieval\Contracts;

interface VectorStoreContract
{
    /**
     * Create the backing collection if it doesn't already exist.
     */
    public function ensureCollection(): void;

    /**
     * Upsert a point. $payload must include 'vendor_id' — every search()
     * call filters on it, so a missing vendor_id silently leaks cross-tenant.
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $payload
     */
    public function upsert(string $pointId, array $vector, array $payload): void;

    /**
     * @param  array<int, float>  $vector
     * @return array<int, array{id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(array $vector, int $vendorId, int $limit, float $scoreThreshold = 0.0): array;

    /**
     * @param  array<int, string>  $pointIds
     */
    public function delete(array $pointIds): void;
}
