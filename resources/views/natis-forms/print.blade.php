<x-layouts.print :title="$natisForm['type']->formNumber().' - '.$natisForm['application']->reference" :branding="$branding">
    <div class="print-controls">
        <a href="{{ route('review.natis-form', $natisForm['application']) }}" style="margin-right: 8px; font-size: 10pt;">Back to the form check</a>
        <button type="button" onclick="printPage()">Print {{ $natisForm['type']->code() }}</button>
    </div>

    @include('natis-forms.form', ['natisForm' => $natisForm, 'breakBefore' => false])
</x-layouts.print>
