GDPR customers/data_request

Shop: {{ $shopDomain }}
Shopify customer id: {{ $customerId ?? '-' }}
Data request id: {{ $requestId ?? '-' }}
Orders requested: {{ implode(', ', $orderIds) ?: '-' }}

TrackFlow stores no customer name, email, phone or address. Below is every tracking row linked
to these orders (and to the same browser identifiers). Send this to the merchant within 30 days.

@forelse ($rows as $row)
- @foreach ($row as $field => $value){{ $field }}: {{ $value ?? '-' }}@if (! $loop->last); @endif @endforeach

@empty
No tracking data is stored for these orders.
@endforelse
