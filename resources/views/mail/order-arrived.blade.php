<x-mail::message>
# {{ $order->lines === [] ? 'New request' : 'New order' }} #{{ $order->number }}

{{ $appName }}@if ($order->is_test) — this is a test order you sent yourself.@endif

@if ($lines !== [])
<x-mail::table>
| Item | Qty | Price |
| :--- | :-: | ----: |
@foreach ($lines as $line)
| {{ $line['title'] ?? 'Item' }} | {{ $line['quantity'] ?? 1 }} | {{ $line['price'] ?? '' }} |
@endforeach
</x-mail::table>

@if ($order->total)
**Total: {{ $order->total }}**
@endif
@endif

@if ($details !== [])
## Customer
@foreach ($details as $detail)
- **{{ $detail['label'] ?? 'Detail' }}:** {{ $detail['value'] ?? '' }}
@endforeach
@endif

<x-mail::button :url="config('app.frontend_url') . '/apps/' . $appId . '/orders'">
Open your orders
</x-mail::button>

Reply to the customer using the details above. This email does not change the
order — open the panel to mark it accepted or done.
</x-mail::message>
