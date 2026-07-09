<?php
declare(strict_types=1);

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

echo "This RSS feed has been removed. Use api.php?resource=latest&limit=25 or export.php for publication data.\n";
