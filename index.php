<?php

// Front controller for deployments served from the project root instead of
// public/ (see the root .htaccess). Running from here, rather than being
// rewritten straight to public/index.php, makes SCRIPT_NAME this folder's
// own URL — so Laravel resolves /wakeel/install as the `/install` route and
// generates every link without a `/public` segment.

require __DIR__.'/public/index.php';
