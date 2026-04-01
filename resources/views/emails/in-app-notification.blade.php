<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notification->title }}</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="margin-top: 0;">{{ $notification->title }}</h2>
    <p>Hello {{ $user->name }},</p>
    @if($notification->body)
        <p>{{ $notification->body }}</p>
    @endif
    @php($frontend = rtrim(config('app.frontend_url', config('app.url')), '/'))
    <p><a href="{{ $frontend }}/admin/kerisi/notifications" style="display: inline-block; padding: 10px 20px; background: #0f172a; color: white; text-decoration: none; border-radius: 6px;">Open notifications</a></p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
    <p style="font-size: 12px; color: #666;">AFSA — Admin for SELAR &amp; AINA. Automated message; do not reply.</p>
</body>
</html>
