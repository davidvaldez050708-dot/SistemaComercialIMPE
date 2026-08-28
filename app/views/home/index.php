<?php

require_once __DIR__ . '/../../../config/config.php';

header(
    'Location: ' .
    BASE_URL .
    'index.php?controller=home&action=index'
);

exit;
