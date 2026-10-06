<?php

namespace App\Http\Controllers;

use App\Actions\RecordAudit;
use App\Models\Application;
use App\Models\BrandingSetting;
use App\Services\NatisFormBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Prints one application's ALV / RLV on its own, outside the pack.
 */
class NatisFormPrintController extends Controller
{
    public function __invoke(Request $request, Application $application, NatisFormBuilder $builder, RecordAudit $audit): View
    {
        Gate::authorize('review', $application);

        $natisForm = $builder->printable($application);
        abort_if($natisForm === null, 404);

        $audit->handle(
            $request->user(),
            $application,
            'natis_form.printed',
            $natisForm['type']->code().' opened for printing.',
        );

        return view('natis-forms.print', [
            'natisForm' => $natisForm,
            'branding' => BrandingSetting::current(),
        ]);
    }
}
