<x-mail::message>
# {{ $headline }}

@if ($cancelled)
{{ $shop }} has cancelled it. If that is a surprise, get in touch with them directly.
@else
From {{ $shop }}.
@endif

@if ($lines !== [])
<x-mail::table>
| Item | Qty |
| :--- | :-: |
@foreach ($lines as $line)
| {{ $plain($line['title'] ?? 'Item') }} | {{ $plain($line['quantity'] ?? 1) }} |
@endforeach
</x-mail::table>

@if ($total)
**Total: {{ $plain($total) }}**
@endif
@endif

You can see all your orders in the {{ $shop }} app, under your account.
</x-mail::message>
