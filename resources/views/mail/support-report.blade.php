<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f1f1f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#303030;font-size:14px;line-height:1.5">
<div style="max-width:720px;margin:0 auto;background:#fff;border-radius:12px;padding:24px">
    <h1 style="font-size:18px;margin:0 0 4px">Support report #{{ $report['report_id'] }}</h1>
    <p style="margin:0 0 16px;color:#616161">{{ $report['shop_domain'] }} · {{ $report['platform_label'] }} · {{ $report['code'] }}</p>

    <table style="border-collapse:collapse;width:100%;margin-bottom:16px" cellpadding="6">
        <tr><td style="color:#616161;width:180px">Shop</td><td>{{ $report['shop_domain'] }} (shop id {{ $report['shop_id'] }})</td></tr>
        <tr><td style="color:#616161">Platform</td><td>{{ $report['platform_label'] }} (integration id {{ $report['integration_id'] ?? 'none' }})</td></tr>
        <tr><td style="color:#616161">Error</td><td><strong>{{ $report['code'] }}</strong> — {{ $report['label'] }}</td></tr>
        <tr><td style="color:#616161">Failures</td><td>{{ $report['count'] }} between {{ $report['first_failed_at'] ?? '—' }} and {{ $report['last_failed_at'] ?? '—' }}</td></tr>
        <tr><td style="color:#616161">Window searched</td><td>{{ $report['period_start'] }} to {{ $report['period_end'] }}</td></tr>
        <tr><td style="color:#616161">Reported at</td><td>{{ $report['reported_at'] }}</td></tr>
        <tr><td style="color:#616161">App version</td><td>{{ $report['app_version'] }}</td></tr>
    </table>

    @if (! empty($report['note']))
        <h2 style="font-size:15px;margin:16px 0 6px">Merchant note</h2>
        <p style="margin:0;padding:10px 12px;background:#f7f7f7;border-radius:8px;white-space:pre-wrap">{{ $report['note'] }}</p>
    @endif

    <h2 style="font-size:15px;margin:16px 0 6px">By event type</h2>
    <table style="border-collapse:collapse;width:100%" cellpadding="6">
        @foreach ($report['events'] as $event)
            <tr style="border-top:1px solid #ebebeb"><td>{{ $event['label'] }} <span style="color:#616161">({{ $event['event'] }})</span></td><td style="text-align:right">{{ $event['count'] }}</td></tr>
        @endforeach
    </table>

    <h2 style="font-size:15px;margin:16px 0 6px">Sample failures (newest first)</h2>
    @foreach ($report['samples'] as $sample)
        <div style="border:1px solid #ebebeb;border-radius:8px;padding:10px 12px;margin-bottom:8px">
            <div>Delivery <strong>{{ $sample['delivery_id'] }}</strong> · event {{ $sample['tracking_event_id'] }} ({{ $sample['event'] }}) · {{ $sample['status'] }} · attempts {{ $sample['attempts'] }}</div>
            <div style="color:#616161">occurred_at {{ $sample['occurred_at'] ?? '—' }} · uploaded_at {{ $sample['uploaded_at'] ?? '—' }} · response {{ $sample['response_code'] ?? '—' }}</div>
            <pre style="margin:8px 0 0;padding:8px;background:#f7f7f7;border-radius:6px;white-space:pre-wrap;word-break:break-word;font-size:12px">{{ $sample['response_body'] ?? '—' }}</pre>
        </div>
    @endforeach

    <h2 style="font-size:15px;margin:16px 0 6px">Look it up</h2>
    <pre style="margin:0;padding:8px;background:#f7f7f7;border-radius:6px;white-space:pre-wrap;font-size:12px">{{ $report['lookup']['sql'] }}
{{ $report['lookup']['tinker'] }}</pre>
</div>
</body>
</html>
