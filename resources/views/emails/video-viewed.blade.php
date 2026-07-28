<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Unique Video View Alert</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
</head>
<body style="margin:0; padding:0; background-color:#f4f7fb; color:#172033; font-family:Inter, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background-color:#f4f7fb;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:600px;">
                    {{-- <tr>
                        <td style="padding:0 8px 18px; color:#56637a; font-size:14px; font-weight:700; letter-spacing:0.4px;">
                            {{ config('app.name', 'Loom') }}
                        </td>
                    </tr> --}}
                    <tr>
                        <td style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 16px rgba(23,32,51,0.08);">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding:30px 32px 26px; background:linear-gradient(135deg,#542f8c 0%,#8448bb 100%); text-align:center;">
                                        {{-- <p style="margin:0 0 10px; color:#fff; font-size:13px; font-weight:700; letter-spacing:1px; text-transform:uppercase;">Viewer activity</p> --}}
                                        <img src="{{ asset('storage/logo.png') }}" alt="" width="250" style="display:block; margin:0 auto 10px;">
                                        <h1 style="margin:0; color:#ffffff; font-size:28px; line-height:34px; font-weight:700;">A new viewer watched your video</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:30px 32px 32px;">
                                        <p style="margin:0 0 24px; color:#4b5870; font-size:16px; line-height:24px;">A unique view has been recorded. Here are the details for this viewing session.</p>

                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e5eaf2; border-radius:10px;">
                                            <tr>
                                                <td style="padding:16px 18px; border-bottom:1px solid #e5eaf2; background-color:#f8faff;">
                                                    <p style="margin:0 0 4px; color:#77839a; font-size:12px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">Video title</p>
                                                    <p style="margin:0; color:#172033; font-size:17px; line-height:24px; font-weight:700;">{{ $video->title }}</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 18px; border-bottom:1px solid #e5eaf2;">
                                                    <p style="margin:0 0 4px; color:#77839a; font-size:12px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">Video UUID</p>
                                                    <p style="margin:0; color:#34415a; font-family:Consolas, Monaco, monospace; font-size:13px; line-height:20px; word-break:break-all;">{{ $video->uuid }}</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 18px 8px;">
                                                    <p style="margin:0; color:#77839a; font-size:12px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">Viewing details</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 18px 16px;">
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                                        <tr>
                                                            <td width="50%" valign="top" style="padding:0 10px 14px 0;">
                                                                <p style="margin:0 0 4px; color:#77839a; font-size:12px;">Viewer IP</p>
                                                                <p style="margin:0; color:#172033; font-size:14px; line-height:20px; word-break:break-word;">{{ $view->viewer_ip ?? 'N/A' }}</p>
                                                            </td>
                                                            <td width="50%" valign="top" style="padding:0 0 14px 10px;">
                                                                <p style="margin:0 0 4px; color:#77839a; font-size:12px;">Watch time</p>
                                                                <p style="margin:0; color:#172033; font-size:14px; line-height:20px; font-weight:700;">{{ $view->watch_seconds }} seconds</p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" valign="top" style="padding:0 0 14px;">
                                                                <p style="margin:0 0 4px; color:#77839a; font-size:12px;">Viewer Agent</p>
                                                                <p style="margin:0; color:#172033; font-size:14px; line-height:20px; word-break:break-all;">{{ $view->viewer_agent ?? 'N/A' }}</p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" valign="top" style="padding:0;">
                                                                <p style="margin:0 0 4px; color:#77839a; font-size:12px;">Viewed at</p>
                                                                <p style="margin:0; color:#172033; font-size:14px; line-height:20px;">{{ $view->created_at?->format('d M Y, h:i A') }}</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:22px 16px 0; color:#8a95a8; font-size:12px; line-height:18px;">{{ config('app.name', 'Loom') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
