<x-mail::message>
# Your account is scheduled for deletion

Hello {{ $name }},

We received a request to delete your WxNotify account. Unless you cancel, the account and everything in it (alert rules, locations, notification channels and delivery history) will be permanently deleted on **{{ $date }}**.

Your alerts are paused and you have been signed out everywhere, including the WxNotify app.

To keep your account, sign in on the website before then and choose **Cancel deletion**.

<x-mail::button :url="$profileUrl">
Cancel deletion
</x-mail::button>

If you asked for this, there is nothing more to do. If you did not, sign in and cancel it, then change your password.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
