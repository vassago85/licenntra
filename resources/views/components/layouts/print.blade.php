<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($branding = $branding ?? \App\Models\BrandingSetting::current())
    <title>{{ $title ?? ($branding->company_name . ' - printable') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "IBM Plex Sans", "Helvetica Neue", Arial, sans-serif;
            color: #111;
            background: #fff;
            margin: 0;
            padding: 24px;
            font-size: 12pt;
            line-height: 1.4;
        }
        .page {
            max-width: 760px;
            margin: 0 auto;
            padding: 24px;
            border: 1px solid #ccc;
            background: #fff;
        }
        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #111;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }
        .brand-name {
            font-weight: 700;
            font-size: 16pt;
        }
        .doc-title {
            font-size: 14pt;
            font-weight: 700;
            margin: 0 0 4px 0;
        }
        .doc-sub {
            color: #555;
            font-size: 10pt;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px;
        }
        .box {
            border: 1px solid #d4d4d4;
            padding: 10px 12px;
            border-radius: 4px;
        }
        .box h3 {
            margin: 0 0 6px 0;
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            font-size: 10.5pt;
        }
        th, td {
            text-align: left;
            padding: 8px 10px;
            border: 1px solid #d4d4d4;
            vertical-align: top;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 9pt;
            letter-spacing: 0.03em;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 32px;
        }
        .sig-block {
            border-top: 1px solid #111;
            padding-top: 6px;
            min-height: 90px;
        }
        .sig-label {
            font-size: 9pt;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .sig-name {
            margin-top: 4px;
            font-weight: 600;
        }
        .status-chip {
            display: inline-block;
            border: 1px solid #d4d4d4;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .status-chip.completed { background: #e8f7ea; border-color: #a6d9b0; color: #0b4d1e; }
        .status-chip.pending   { background: #fff3d6; border-color: #e8c777; color: #5e4300; }
        .print-controls {
            text-align: right;
            margin-bottom: 12px;
        }
        .print-controls button {
            cursor: pointer;
            padding: 6px 12px;
            font-size: 10pt;
            background: #111;
            color: #fff;
            border: 0;
            border-radius: 4px;
        }
        .narrative {
            white-space: pre-wrap;
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px dashed #c0c0c0;
            border-radius: 4px;
            background: #fafafa;
            font-size: 10.5pt;
        }
        @media print {
            body { padding: 0; background: #fff; }
            .page { border: 0; max-width: none; padding: 0; }
            .print-controls { display: none; }
        }
    </style>
</head>
<body>
<div class="page">
    {{ $slot }}
</div>
<script>
    function printPage() { window.print(); }
</script>
</body>
</html>
