Support report #{{ $report['report_id'] }} from {{ $report['shop_domain'] }}

Shop: {{ $report['shop_domain'] }} (shop id {{ $report['shop_id'] }})
Platform: {{ $report['platform_label'] }} (integration id {{ $report['integration_id'] ?? 'none' }})
Error: {{ $report['code'] }} - {{ $report['label'] }}
Failures: {{ $report['count'] }} between {{ $report['first_failed_at'] ?? '-' }} and {{ $report['last_failed_at'] ?? '-' }}
Window searched: {{ $report['period_start'] }} to {{ $report['period_end'] }}
Reported at: {{ $report['reported_at'] }}
App version: {{ $report['app_version'] }}
@if (! empty($report['note']))

Merchant note:
{{ $report['note'] }}
@endif

By event type:
@foreach ($report['events'] as $event)
- {{ $event['label'] }} ({{ $event['event'] }}): {{ $event['count'] }}
@endforeach

Sample failures (newest first):
@foreach ($report['samples'] as $sample)
- delivery {{ $sample['delivery_id'] }}, event {{ $sample['tracking_event_id'] }} ({{ $sample['event'] }}), status {{ $sample['status'] }}, attempts {{ $sample['attempts'] }}
  occurred_at: {{ $sample['occurred_at'] ?? '-' }}
  uploaded_at: {{ $sample['uploaded_at'] ?? '-' }}
  response {{ $sample['response_code'] ?? '-' }}: {{ $sample['response_body'] ?? '-' }}
@endforeach

Look it up:
{{ $report['lookup']['sql'] }}
{{ $report['lookup']['tinker'] }}
