<?php

namespace App\Services\Publishing;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ChangeOperationRemover
{
    public function remove(ChangeOperation $operation): void
    {
        $payloadPath = $operation->payload_path;

        DB::transaction(function () use ($operation): void {
            $changeSet = ChangeSet::query()->lockForUpdate()->findOrFail($operation->change_set_id);

            if (! in_array($changeSet->state, ['draft', 'validated', 'failed', 'stale'], true)) {
                throw new RuntimeException('Changes cannot be removed while this change set is queued, publishing, or published.');
            }

            ChangeOperation::query()
                ->whereKey($operation->getKey())
                ->where('change_set_id', $changeSet->getKey())
                ->firstOrFail()
                ->delete();

            $changeSet->update([
                'state' => 'draft',
                'validation_report' => null,
            ]);
        });

        if (is_string($payloadPath) && $payloadPath !== '' && ! ChangeOperation::query()->where('payload_path', $payloadPath)->exists()) {
            Storage::disk('ota-private')->delete($payloadPath);
        }
    }
}
