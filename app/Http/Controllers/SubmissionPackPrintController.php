<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\Application;
use App\Models\BrandingSetting;
use App\Models\DocumentVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Print view for one or more submission packs (?ids=1,2,3). Each pack
 * renders the versions frozen in its manifest, not whatever was uploaded
 * since, so the printout always matches the audit record.
 */
class SubmissionPackPrintController extends Controller
{
    public function __invoke(Request $request, RecordAudit $audit): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $applications = Application::query()
            ->whereIn('id', $ids)
            ->with(['clientAccount', 'vehicle', 'businessClient', 'titleHolder', 'latestSubmissionPack.preparedBy'])
            ->get()
            ->sortBy(fn (Application $application): int => (int) $ids->search($application->id))
            ->values();

        abort_if($applications->count() !== $ids->count(), 404);

        foreach ($applications as $application) {
            Gate::authorize('review', $application);
        }

        $packs = $applications->map(function (Application $application) use ($request, $audit): array {
            $pack = $application->latestSubmissionPack;

            if ($pack !== null) {
                $audit->handle(
                    $request->user(),
                    $application,
                    'submission_pack.printed',
                    'Submission pack #'.$pack->id.' opened for printing.',
                    null,
                    ['submission_pack_id' => $pack->id],
                );
            }

            return [
                'application' => $application,
                'pack' => $pack,
                'versions' => $pack === null
                    ? collect()
                    : DocumentVersion::query()->whereIn('id', $pack->versionIds())->get()->keyBy('id'),
                'queryNote' => $application->latestAuthorityQueryNote(),
            ];
        });

        return view('submission-packs.print', [
            'packs' => $packs,
            'branding' => BrandingSetting::current(),
        ]);
    }
}
