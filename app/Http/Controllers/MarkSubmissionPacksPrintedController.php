<?php

namespace App\Http\Controllers;

use App\Actions\MarkSubmissionPackPrinted;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Explicit "these packs are on paper" acknowledgement posted from the pack
 * print view (?ids=1,2,3), so lodgement work only shows a pack as printed
 * once someone has said so.
 */
class MarkSubmissionPacksPrintedController extends Controller
{
    public function __invoke(Request $request, MarkSubmissionPackPrinted $markPrinted): RedirectResponse
    {
        $ids = collect(explode(',', (string) $request->input('ids')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $applications = Application::query()->whereIn('id', $ids)->get();

        abort_if($applications->count() !== $ids->count(), 404);

        foreach ($applications as $application) {
            Gate::authorize('review', $application);
        }

        $back = redirect()->route('review.packs.print', ['ids' => $ids->implode(',')]);

        try {
            DB::transaction(function () use ($applications, $markPrinted, $request): void {
                foreach ($applications as $application) {
                    $markPrinted->handle($application, $request->user());
                }
            });
        } catch (ValidationException $exception) {
            return $back->withErrors($exception->validator->getMessageBag());
        }

        return $back->with('status', $ids->count() === 1
            ? 'Pack marked as printed.'
            : $ids->count().' '.Str::plural('pack', $ids->count()).' marked as printed.');
    }
}
