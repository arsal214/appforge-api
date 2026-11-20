<x-mail::message>
# Your code

Type this code into the {{ $shop }} app to set a new password:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It works once, for the next {{ $minutes }} minutes.

If you did not ask for this, you can ignore this email. Your password has not
changed.
</x-mail::message>
