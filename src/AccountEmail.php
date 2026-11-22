<?php
declare(strict_types=1);

/** Private account mail using the tracker's established branding and MIME transport. */
final class AccountEmail
{
    public static function signIn(string $email, string $url): array
    {
        $link = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "Sign in to Psilocybin Research\n\nOpen this link to sign in:\n".$url."\n\nThis link expires in 15 minutes and can be used once. No password is needed.\n\nIf you did not request this email, you can safely ignore it. Do not forward this sign-in link.\n\nPsilocybin-Research.com\n";
        $html = <<<HTML
<tr><td style="padding:26px 24px"><h1 style="margin:0;font-size:25px;line-height:1.18">Your sign-in link</h1>
<p style="margin:10px 0;color:#686c61;font-size:15px;line-height:1.55">Use the button below to sign in to your Psilocybin Research account. You can open it in any browser or on another device.</p>
<p style="margin:22px 0"><a href="$link" style="display:inline-block;background:#123c31;color:#fff;text-decoration:none;border:1px solid #123c31;border-radius:6px;padding:12px 18px;font-size:14px;font-weight:800">Sign in</a></p>
<p style="margin:0;color:#686c61;font-size:13px;line-height:1.55">This link expires in <strong>15 minutes</strong> and can be used once. No password is needed.</p>
<div style="margin-top:18px;padding:14px;border:1px solid #d8d2c4;border-radius:6px;background:#f7f6f1;color:#686c61;font-size:12px;line-height:1.55">If you did not request this email, you can safely ignore it. Do not forward this sign-in link.</div></td></tr>

HTML;
        $html = EmailTemplate::render($html, 'Your single-use sign-in link expires in 15 minutes.', 'Account access');
        $db = new Database();
        return (new AlertService($db, new PublicationRepository($db)))->buildMailMessage([
            'subscription' => ['email' => $email],
            'subject' => 'Your Psilocybin Research sign-in link',
            'text' => $text,
            'html' => $html,
            'headers' => [
                'From' => 'Psilocybin Research <'.Config::alertFromEmail().'>',
                'Auto-Submitted' => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
            ],
        ]);
    }
}
