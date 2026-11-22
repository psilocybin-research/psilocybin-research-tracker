<?php
declare(strict_types=1);

/** Shared, email-client-safe frame for all transactional tracker emails. */
final class EmailTemplate
{
    public static function render(string $rows,string $preheader,string $footer='Coverage may be incomplete; verify records before citation or clinical use.',int $width=680): string
    {
        $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $home=$e(rtrim(Config::publicBaseUrl(),'/').'/');$preview=$e($preheader);$foot=$e($footer);$width=min(720,max(600,$width));
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no"><style>a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}</style></head>'
            .'<body style="margin:0;padding:0;background:#ffffff;color:#24251f;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">'
            .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.$preview.'</div>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;"><tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:'.$width.'px;background:#ffffff;border:1px solid #d8d2c4;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(36,37,31,.08);">'
            .'<tr><td style="background:#ffffff;padding:18px 24px;border-bottom:1px solid #d8d2c4;border-left:4px solid #123c31;">'
            .'<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="width:54px;padding-right:13px;"><a href="'.$home.'" style="text-decoration:none;border:0;"><img src="cid:psilocybin-research-logo" width="46" height="46" alt="" style="display:block;width:46px;height:46px;border:0;border-radius:7px;"></a></td>'
            .'<td><a href="'.$home.'" style="display:inline-block;color:#24251f!important;font-size:18px;font-weight:800;line-height:1.15;text-decoration:none!important;"><span style="color:#24251f;">Psilocybin-Research.com</span></a><div style="margin-top:3px;color:#1a6b54;font-size:12px;font-weight:700;line-height:1.3;">Searchable psilocybin and psilocin bibliometric database.</div></td></tr></table></td></tr>'
            .$rows
            .'<tr><td style="padding:14px 24px;background:#f7f6f1;border-top:1px solid #d8d2c4;color:#686c61;font-size:11px;line-height:1.5;"><a href="'.$home.'" style="color:#686c61!important;text-decoration:none!important;">Psilocybin-Research.com</a> · '.$foot.'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
