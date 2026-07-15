{{-- Shared skeleton for all PDF documents. Expects $municipality. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif; /* unicode currency symbols */
            font-size: 10px;
            color: #1e293b;
            padding-bottom: 40px;
        }
        .letterhead { border-bottom: 2px solid #059669; padding-bottom: 10px; margin-bottom: 16px; }
        .letterhead .muni-name { font-size: 18px; font-weight: bold; color: #059669; }
        .letterhead .muni-contact { color: #64748b; margin-top: 2px; }
        .letterhead .doc-title { font-size: 15px; font-weight: bold; text-transform: uppercase; color: #334155; }
        .letterhead .doc-subtitle { color: #64748b; font-size: 10px; }
        .meta-grid { width: 100%; margin-bottom: 14px; }
        .meta-grid td { vertical-align: top; padding: 0; }
        .label { color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; }
        .value { font-weight: bold; margin-bottom: 6px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.lines th {
            text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px;
            color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 4px 6px;
        }
        table.lines td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
        table.lines .num { text-align: right; }
        table.lines th.num { text-align: right; }
        .totals { width: 240px; margin-left: auto; margin-right: 0; border-collapse: collapse; }
        .totals td { padding: 3px 6px; }
        .totals .num { text-align: right; }
        .totals .grand td { border-top: 1px solid #334155; font-weight: bold; font-size: 12px; }
        .muted { color: #64748b; }
        .mono { font-family: "DejaVu Sans Mono", monospace; }
        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            border-top: 1px solid #e2e8f0; padding-top: 5px;
            color: #94a3b8; font-size: 8px;
        }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    @yield('content')

    <div class="footer">
        {{ $municipality->name }} &middot; Generated {{ now()->format('d M Y H:i') }} &middot; Olimem O-Billing
    </div>
</body>
</html>
