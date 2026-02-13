<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <!-- Campaign Content -->
                    <tr>
                        <td style="padding: 32px 40px;">
                            {!! $body_html !!}
                        </td>
                    </tr>

                    <!-- Footer with tracking pixel and unsubscribe -->
                    <tr>
                        <td style="padding: 24px 40px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #9ca3af; text-align: center;">
                                You are receiving this email because you subscribed to the {{ $siteName ?? 'our' }} newsletter.
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 13px; line-height: 1.5; color: #9ca3af; text-align: center;">
                                <a href="{{ $unsubscribe_url }}" style="color: #6b7280; text-decoration: underline;">Unsubscribe</a>
                            </p>
                            @if (isset($tracking_pixel_url))
                                <img src="{{ $tracking_pixel_url }}" width="1" height="1" alt="" style="display: block; width: 1px; height: 1px; border: 0;">
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
