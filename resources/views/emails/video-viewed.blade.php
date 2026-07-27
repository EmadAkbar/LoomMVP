<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unique Video View Alert</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937;">
<h2 style="margin-bottom: 8px;">Unique Video View Alert</h2>
<p style="margin-top: 0;">A new unique viewer watched a video.</p>

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
        <td><strong>Viewer IP:</strong></td>
        <td>{{ $view->viewer_ip ?? 'N/A' }}</td>
    </tr>
    <tr>
        <td><strong>Viewer Device ID (MAC/Fingerprint):</strong></td>
        <td>{{ $view->viewer_device_id ?? 'N/A' }}</td>
    </tr>
    <tr>
        <td><strong>Watch Seconds:</strong></td>
        <td>{{ $view->watch_seconds }}</td>
    </tr>
    <tr>
        <td><strong>Viewed At:</strong></td>
        <td>{{ $view->created_at?->toDateTimeString() }}</td>
    </tr>
</table>
</body>
</html>
