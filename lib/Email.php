<?php
/**
 * Email Utility Class
 *
 * Handles sending emails for magic links, invitations, etc.
 * Supports both SendGrid and PHP mail() function.
 */

require_once __DIR__ . '/../config/env.php';

class Email {
    private $provider;
    private $fromEmail;
    private $fromName;
    private $apiKey;

    public function __construct() {
        $this->provider = Env::get('EMAIL_PROVIDER', 'mail'); // 'sendgrid' or 'mail'
        $this->fromEmail = Env::get('EMAIL_FROM', 'noreply@teamselevated.com');
        $this->fromName = Env::get('EMAIL_FROM_NAME', 'Teams Elevated');
        $this->apiKey = Env::get('SENDGRID_API_KEY', '');
    }

    /**
     * Send magic link email
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $magicLink The magic link URL
     * @return bool Success status
     */
    public function sendMagicLink($to, $name, $magicLink) {
        $subject = 'Your Teams Elevated Login Link';

        $htmlBody = $this->getMagicLinkTemplate($name, $magicLink);
        $textBody = "Hi $name,\n\n" .
                    "Click the link below to sign in to Teams Elevated:\n\n" .
                    "$magicLink\n\n" .
                    "This link expires in 15 minutes.\n\n" .
                    "If you didn't request this link, you can safely ignore this email.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send team invitation email
     *
     * @param string $to Recipient email
     * @param string $teamName Team name
     * @param string $invitedBy Name of person who invited
     * @param string $invitationLink The invitation link
     * @param string $personalMessage Optional personal message
     * @return bool Success status
     */
    public function sendTeamInvitation($to, $teamName, $invitedBy, $invitationLink, $personalMessage = '') {
        $subject = "You're invited to join $teamName";

        $htmlBody = $this->getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage);
        $textBody = "Hi,\n\n" .
                    "$invitedBy has invited you to join $teamName on Teams Elevated.\n\n" .
                    ($personalMessage ? "$personalMessage\n\n" : '') .
                    "Click the link below to accept your invitation:\n\n" .
                    "$invitationLink\n\n" .
                    "This invitation expires in 90 days.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email (main method)
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML body
     * @param string $textBody Plain text body
     * @return bool Success status
     */
    private function send($to, $subject, $htmlBody, $textBody) {
        if ($this->provider === 'sendgrid' && !empty($this->apiKey)) {
            return $this->sendViaSendGrid($to, $subject, $htmlBody, $textBody);
        } else {
            return $this->sendViaPHPMail($to, $subject, $htmlBody, $textBody);
        }
    }

    /**
     * Send email via SendGrid API
     */
    private function sendViaSendGrid($to, $subject, $htmlBody, $textBody) {
        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName
            ],
            'content' => [
                ['type' => 'text/plain', 'value' => $textBody],
                ['type' => 'text/html', 'value' => $htmlBody]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Email sent successfully to $to via SendGrid");
            return true;
        } else {
            error_log("SendGrid API error ($httpCode): $response");
            return false;
        }
    }

    /**
     * Send email via PHP mail() function
     */
    private function sendViaPHPMail($to, $subject, $htmlBody, $textBody) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        $sent = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

        if ($sent) {
            error_log("Email sent successfully to $to via PHP mail()");
        } else {
            error_log("Failed to send email to $to via PHP mail()");
        }

        return $sent;
    }

    /**
     * Magic link email template
     */
    private function getMagicLinkTemplate($name, $magicLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2d5016 0%, #3d6b1f 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #2d5016; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Hi {$name},</h2>
            <p>Click the button below to sign in to Teams Elevated:</p>
            <p style="text-align: center;">
                <a href="{$magicLink}" class="button">Sign In to Teams Elevated</a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This link expires in 15 minutes. If you didn't request this link, you can safely ignore this email.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$magicLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Team invitation email template
     */
    private function getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage) {
        $messageHtml = $personalMessage ? "<p style='background: #fff; padding: 15px; border-left: 4px solid #2d5016; margin: 20px 0;'><em>\"$personalMessage\"</em></p>" : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2d5016 0%, #3d6b1f 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #2d5016; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>You're Invited!</h1>
        </div>
        <div class="content">
            <h2>Join {$teamName}</h2>
            <p><strong>{$invitedBy}</strong> has invited you to join <strong>{$teamName}</strong> on Teams Elevated.</p>
            {$messageHtml}
            <p style="text-align: center;">
                <a href="{$invitationLink}" class="button">Accept Invitation</a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This invitation is valid for 90 days.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$invitationLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
