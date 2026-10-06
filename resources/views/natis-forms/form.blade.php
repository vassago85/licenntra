@php
    $formType = $natisForm['type'];
    $values = $natisForm['values'];
    $formApplication = $natisForm['application'];
    $breakBefore = $breakBefore ?? true;
    $declarationText = '(a) declare that all the particulars furnished by me in this form are true and correct; and (b) realise that a false declaration is punishable with a fine or imprisonment or both.';
@endphp

@once
    <style>
        .natis { font-size: 9pt; line-height: 1.3; }
        .natis.break { page-break-before: always; break-before: page; margin-top: 40px; padding-top: 20px; border-top: 1px dashed #bbb; }
        .natis-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border: 2px solid #111; padding: 8px 10px; }
        .natis-head .code { font-size: 24pt; font-weight: 800; line-height: 1; }
        .natis-head .title { font-size: 12pt; font-weight: 700; text-transform: uppercase; }
        .natis .sub { font-size: 8.5pt; color: #444; }
        .natis-note { margin-top: 6px; font-size: 8.5pt; color: #333; }
        .natis-section { margin-top: 8px; border: 1px solid #111; break-inside: avoid; page-break-inside: avoid; }
        .natis-section h4 { margin: 0; padding: 3px 8px; background: #111; color: #fff; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; display: flex; align-items: center; gap: 8px; }
        .natis-section h4 .part { background: #fff; color: #111; padding: 0 6px; font-weight: 800; }
        .natis-section h4 .hint { text-transform: none; letter-spacing: 0; font-weight: 400; opacity: 0.85; }
        .natis table { margin: 0; font-size: 9pt; }
        .natis th, .natis td { padding: 3px 6px; border: 1px solid #bbb; }
        .natis td.label { width: 34%; color: #333; }
        .natis .val { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 600; }
        .natis .xbox { display: inline-block; width: 12px; height: 12px; border: 1px solid #111; text-align: center; font-size: 8pt; font-weight: 800; line-height: 11px; margin-right: 3px; vertical-align: -2px; }
        .natis .opt { display: inline-block; margin-right: 10px; white-space: nowrap; }
        .natis .declare { padding: 6px 8px; font-size: 8.5pt; }
        .natis .sign-row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; padding: 4px 8px 10px; align-items: end; }
        .natis .sign-line { border-bottom: 1px solid #111; min-height: 22px; }
        .natis .stamp { border: 1px dashed #777; min-height: 60px; padding: 4px; font-size: 8pt; color: #555; }
        .natis .office td { height: 22px; }
        .natis-warning { border: 1px solid #e8c777; background: #fff8e6; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px; font-size: 10pt; }
        @media print {
            .natis.break { margin-top: 0; padding-top: 0; border-top: 0; }
            .natis-screen-only { display: none !important; }
        }
    </style>
@endonce

<section @class(['natis', 'break' => $breakBefore])>
    @unless ($natisForm['isChecked'])
        <div class="natis-warning natis-screen-only">
            Operations have not checked this {{ $formType->code() }} yet. The values below come straight from the application.
            <a href="{{ route('review.natis-form', $formApplication) }}">Check the form</a> before lodging it.
        </div>
    @endunless

    <div class="natis-head">
        <div>
            <div class="sub">Republic of South Africa</div>
            <div class="title">{{ $formType->title() }}</div>
            <div class="sub">(National Road Traffic Act, 1996)</div>
        </div>
        <div style="text-align: right;">
            <div class="code">{{ $formType->code() }}</div>
            <div class="sub">{{ $formType->formNumber() }} · {{ $formType === \App\Enums\NatisFormType::Rlv ? 'Blue' : 'Green' }}</div>
            <div class="sub val">{{ $formApplication->reference }}</div>
        </div>
    </div>
    <div class="natis-note">
        Acceptable identification of the {{ $formType === \App\Enums\NatisFormType::Rlv ? 'title holder and/or owner' : 'owner' }} is essential (including that of the proxy and/or representative).
    </div>

    @foreach ($natisForm['sections'] as $section)
        @php
            $sectionValues = $values[$section['key']] ?? [];
            $isDeclaration = str_ends_with($section['key'], 'declaration');
        @endphp
        <div class="natis-section">
            <h4>
                @if ($section['part'])
                    <span class="part">{{ $section['part'] }}</span>
                @endif
                <span>{{ $section['title'] }}</span>
                @if ($section['hint'])
                    <span class="hint">{{ $section['hint'] }}</span>
                @endif
            </h4>

            @if ($isDeclaration)
                <div class="declare">
                    <div>
                        I, the
                        @foreach ($section['fields'][0]['options'] as $optionValue => $optionLabel)
                            <span class="opt"><span class="xbox">{{ ($sectionValues['declarant'] ?? '') === $optionValue ? 'X' : '' }}</span>{{ $optionLabel }}</span>
                        @endforeach
                    </div>
                    <div style="margin-top: 4px;">{{ $declarationText }}</div>
                </div>
                <div class="sign-row">
                    <div>
                        <div class="sign-line"></div>
                        <div class="sub">Signature</div>
                        <div style="margin-top: 6px;">Place: <span class="val">{{ $sectionValues['place'] ?? '' }}</span></div>
                        <div>Date: <span class="val">{{ $sectionValues['date'] ?? '' }}</span></div>
                    </div>
                    @if (array_key_exists('motor_dealer', $section['fields'][0]['options']))
                        <div class="stamp">Dealer stamp</div>
                    @endif
                </div>
            @else
                <table>
                    <tbody>
                        @foreach ($section['fields'] as $field)
                            @php
                                $value = $sectionValues[$field['key']] ?? '';
                            @endphp
                            <tr>
                                <td class="label">{{ $field['label'] }}</td>
                                <td>
                                    @if ($field['type'] === \App\Services\NatisFormBuilder::TYPE_CHOICE)
                                        @foreach ($field['options'] as $optionValue => $optionLabel)
                                            <span class="opt"><span class="xbox">{{ $value === (string) $optionValue ? 'X' : '' }}</span>{{ $optionLabel }}</span>
                                        @endforeach
                                    @elseif ($field['type'] === \App\Services\NatisFormBuilder::TYPE_FLAG)
                                        <span class="xbox">{{ $value ? 'X' : '' }}</span>
                                    @else
                                        <span class="val">{{ $value }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <div class="natis-section office">
        <h4><span>For office use only</span></h4>
        <table>
            <tbody>
                <tr><td class="label">Date of application (effective date)</td><td></td></tr>
                <tr><td class="label">Counter official (name, signature, date)</td><td></td></tr>
                @if ($formType === \App\Enums\NatisFormType::Rlv)
                    <tr><td class="label">Recommending official (name, signature, date)</td><td></td></tr>
                    <tr><td class="label">Authorising official (name, signature, date)</td><td></td></tr>
                @endif
                <tr><td class="label">Data capturing official (name, signature, date)</td><td></td></tr>
                @if ($formType === \App\Enums\NatisFormType::Rlv)
                    <tr><td class="label">Serial number of registration certificate issued</td><td></td></tr>
                @endif
                <tr><td class="label">Serial number of vehicle licence issued</td><td></td></tr>
            </tbody>
        </table>
    </div>
</section>
