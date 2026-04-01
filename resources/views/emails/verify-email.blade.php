<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your email</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>Verify your email</h2>
    <p>Hello {{ $userName }},</p>
    <p>Please confirm your email address by clicking the button below:</p>
    <p><a href="{{ $verificationUrl }}" style="display: inline-block; padding: 10px 20px; background: #1e293b; color: white; text-decoration: none; border-radius: 6px;">Verify email</a></p>
    <p>This link expires in 60 minutes.</p>
    <p>If you did not register, you can ignore this message.</p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
    <p style="font-size: 12px; color: #666;">AFSA — automated message; do not reply.</p>
</body>
</html>
