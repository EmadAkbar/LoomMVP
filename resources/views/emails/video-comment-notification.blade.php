<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Comment Notification</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937;">
<h2 style="margin-bottom: 8px;">New Comment on Your Video</h2>
<p style="margin-top: 0;">Your video received a new comment.</p>

<table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
    <tr>
        <td><strong>Video Title:</strong></td>
        <td>{{ $video->title }}</td>
    </tr>
    <tr>
        <td><strong>Video UUID:</strong></td>
        <td>{{ $video->uuid }}</td>
    </tr>
    <tr>
        <td><strong>Commenter:</strong></td>
        <td>{{ $comment->guest_name ?: 'Authenticated User #' . ($comment->user_id ?? 'Unknown') }}</td>
    </tr>
    <tr>
        <td><strong>Timestamp (s):</strong></td>
        <td>{{ $comment->timestamp_seconds }}</td>
    </tr>
    <tr>
        <td><strong>Comment:</strong></td>
        <td>{{ $comment->comment }}</td>
    </tr>
    <tr>
        <td><strong>Created At:</strong></td>
        <td>{{ $comment->created_at?->toDateTimeString() }}</td>
    </tr>
</table>
</body>
</html>
