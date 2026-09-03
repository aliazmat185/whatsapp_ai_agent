<?php

namespace App\Console\Commands\Products;

use App\Jobs\Ai\ReindexProductJob;
use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Dispatches ReindexProductJob for every product — used after switching
 * embedding providers/models (a new vector space needs everything
 * re-embedded) or recovering from a period where reindexing was failing
 * (e.g. an exhausted embedding-provider quota, which leaves content_hash
 * null and would otherwise only self-heal one product at a time as each
 * gets edited).
 */
#[Signature('products:reindex-all')]
#[Description('Dispatch ReindexProductJob for every product')]
class ReindexAllCommand extends Command
{
    public function handle(): int
    {
        $ids = Product::withoutGlobalScope('vendor')->pluck('id');

        $this->info("Dispatching reindex for {$ids->count()} products...");

        $ids->each(fn (int $id) => ReindexProductJob::dispatch($id));

        $this->info('Done.');

        return self::SUCCESS;
    }
}
