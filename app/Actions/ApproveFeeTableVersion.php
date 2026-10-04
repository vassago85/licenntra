<?php

namespace App\Actions;

use App\Models\FeeTableVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApproveFeeTableVersion
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(FeeTableVersion $version, User $actor): FeeTableVersion
    {
        if ($version->created_by !== null && (int) $version->created_by === (int) $actor->id) {
            throw ValidationException::withMessages([
                'approved_by' => 'The approver must not be the person who created this version.',
            ]);
        }

        FeeTableVersion::query()
            ->where('fee_table_id', $version->fee_table_id)
            ->where('id', '!=', $version->id)
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        $version->status = 'active';
        $version->approved_by = $actor->id;
        $version->approved_at = now();
        $version->save();

        $this->audit->handle($actor, $version, 'fee_table.approved', 'Fee table version approved.', null, [
            'version' => $version->version,
        ]);

        return $version->refresh();
    }
}
