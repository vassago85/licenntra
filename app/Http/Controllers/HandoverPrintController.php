<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\DocumentHandover;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class HandoverPrintController extends Controller
{
    public function __invoke(DocumentHandover $handover): View
    {
        Gate::authorize('print', $handover);

        $handover->load([
            'applications.vehicle',
            'applications.businessClient',
            'createdBy',
            'confirmedBy',
            'clientAccount',
        ]);

        return view('handovers.print', [
            'handover' => $handover,
            'branding' => BrandingSetting::current(),
        ]);
    }
}
