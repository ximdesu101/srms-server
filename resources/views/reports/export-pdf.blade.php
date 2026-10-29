<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 4px 0; text-align: center; }
        h2 { font-size: 13px; margin: 0 0 8px 0; text-align: center; }
        .meta { text-align: center; font-size: 9px; color: #555; margin-bottom: 16px; }
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11px; font-weight: bold; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; font-size: 8px; }
        th { background: #e8eef5; font-weight: bold; }
        .metrics { display: table; width: 100%; }
        .metric { display: table-cell; width: 20%; padding: 6px; border: 1px solid #ddd; text-align: center; }
        .metric-label { font-size: 8px; color: #666; }
        .metric-value { font-size: 14px; font-weight: bold; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 8px; color: #888; }
        .empty { text-align: center; color: #666; font-style: italic; padding: 20px; }
    </style>
</head>
<body>
    <h1>{{ $systemName }}</h1>
    <h2>{{ $title }}</h2>
    <div class="meta">
        Reporting Period: {{ $period }} &nbsp;|&nbsp; Generated: {{ $generatedAt }}
    </div>

    @if($metrics)
    <div class="section">
        <div class="section-title">Summary Statistics</div>
        <table>
            <tr>
                <th>Teachers</th>
                <th>Staff</th>
                <th>School Records</th>
                <th>Doc. Requests</th>
                <th>Pending</th>
                <th>Fulfilled</th>
                <th>Unavailable</th>
            </tr>
            <tr>
                <td>{{ $metrics['teachers']['total'] ?? 0 }} (Active: {{ $metrics['teachers']['active'] ?? 0 }})</td>
                <td>{{ $metrics['staff']['total'] ?? 0 }}</td>
                <td>{{ $metrics['school_records']['total'] ?? 0 }}</td>
                <td>{{ $metrics['document_requests']['total'] ?? 0 }}</td>
                <td>{{ $metrics['document_requests']['pending'] ?? 0 }}</td>
                <td>{{ $metrics['document_requests']['fulfilled'] ?? 0 }}</td>
                <td>{{ $metrics['document_requests']['unavailable'] ?? 0 }}</td>
            </tr>
        </table>
    </div>
    @endif

    <div class="section">
        <div class="section-title">Detailed Records</div>
        @if(empty($rows))
            <div class="empty">No records match the selected criteria.</div>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach(array_keys($rows[0]) as $header)
                            <th>{{ ucwords(str_replace('_', ' ', $header)) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            @foreach($row as $cell)
                                <td>{{ is_array($cell) ? json_encode($cell) : $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="footer">
        Page <span class="pagenum"></span> — {{ $systemName }} — Confidential
    </div>
</body>
</html>