<?php
/**
 * Brevo (Sendinblue) transactional email helper
 * Requires BREVO_API_KEY constant defined in secrets.php
 */

function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $htmlContent): bool {
    if (!defined('BREVO_API_KEY') || !BREVO_API_KEY) return false;

    $payload = json_encode([
        'sender'      => ['name' => 'BeSix Time', 'email' => 'noreply@besix.cz'],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlContent,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

function buildInviteEmail(string $inviterName, string $projectName, string $inviteUrl): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f0;font-family:Inter,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f0;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#2E3029;border-radius:12px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#4A5340;padding:28px 36px;text-align:center;">
            <span style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;">BeSix Time</span>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 36px 28px;color:#d4d9cc;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#d4d9cc;">
              Dobrý den,
            </p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#d4d9cc;">
              <strong style="color:#ffffff;">{$inviterName}</strong> vás pozval/a ke spolupráci na projektu
              <strong style="color:#ffffff;">{$projectName}</strong> v aplikaci <strong style="color:#ffffff;">BeSix Time</strong>.
            </p>
            <p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#d4d9cc;">
              Kliknutím na tlačítko níže pozvánku přijmete a získáte přístup k projektu.
            </p>
            <!-- Button -->
            <table cellpadding="0" cellspacing="0" width="100%">
              <tr><td align="center">
                <a href="{$inviteUrl}"
                   style="display:inline-block;padding:14px 36px;background:#4A5340;color:#ffffff;
                          text-decoration:none;border-radius:8px;font-size:15px;font-weight:700;
                          letter-spacing:0.3px;">
                  Přijmout pozvánku
                </a>
              </td></tr>
            </table>
            <p style="margin:24px 0 0;font-size:12px;color:#6b7360;line-height:1.5;">
              Pokud tlačítko nefunguje, zkopírujte tento odkaz do prohlížeče:<br>
              <a href="{$inviteUrl}" style="color:#8a9c7a;">{$inviteUrl}</a>
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 36px;border-top:1px solid #3d4035;text-align:center;">
            <p style="margin:0;font-size:11px;color:#6b7360;">
              Tuto zprávu jste obdrželi, protože vás někdo pozval do projektu v BeSix.cz.<br>
              Pokud si myslíte, že jde o chybu, e-mail jednoduše ignorujte.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function buildAddedToProjectEmail(string $inviterName, string $projectName, string $projectUrl): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f0;font-family:Inter,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f0;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#2E3029;border-radius:12px;overflow:hidden;">
        <tr>
          <td style="background:#4A5340;padding:28px 36px;text-align:center;">
            <span style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;">BeSix Time</span>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 36px 28px;color:#d4d9cc;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#d4d9cc;">Dobrý den,</p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#d4d9cc;">
              <strong style="color:#ffffff;">{$inviterName}</strong> vás přidal/a do projektu
              <strong style="color:#ffffff;">{$projectName}</strong> v aplikaci <strong style="color:#ffffff;">BeSix Time</strong>.
            </p>
            <p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#d4d9cc;">
              Projekt si můžete otevřít kliknutím na tlačítko níže.
            </p>
            <table cellpadding="0" cellspacing="0" width="100%">
              <tr><td align="center">
                <a href="{$projectUrl}"
                   style="display:inline-block;padding:14px 36px;background:#4A5340;color:#ffffff;
                          text-decoration:none;border-radius:8px;font-size:15px;font-weight:700;">
                  Otevřít projekt
                </a>
              </td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 36px;border-top:1px solid #3d4035;text-align:center;">
            <p style="margin:0;font-size:11px;color:#6b7360;">
              BeSix.cz &mdash; pokud si myslíte, že jde o chybu, e-mail ignorujte.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
