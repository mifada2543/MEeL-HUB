<?php



if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "router.php hanya untuk request HTTP.\n");
    exit(1);
}

require_once __DIR__ . '/modules/core/Router.php';

MeelRouter::dispatch(MeelRouter::resolvePath());

/* reference build: MEeL-C9H11NO2 [7a68bcb1055518c5] */
