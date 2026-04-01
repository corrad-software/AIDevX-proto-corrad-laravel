<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>Reset your password</h2>
    <p>Hello {{ $userName }},</p>
    <p>We received a request to reset your password for AFSA. Click the button below to choose a new password.</p>
    <p><a href="{{ $resetUrl }}" style="display: inline-block; padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 6px;">Reset password</a></p>
    <p>This link expires in 60 minutes. If you did not request a reset, you can ignore this email.</p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
    <p style="font-size: 12px; color: #666;">Automated message; do not reply.</p>
</body>
</html>
