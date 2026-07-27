<?php

$request = json_decode(stream_get_contents(STDIN));
$parametersAreAnObject = is_object($request?->parameters ?? null);

echo json_encode([
    'ok' => $parametersAreAnObject,
    'message' => $parametersAreAnObject ? 'valid' : 'parameters must be an object',
    'data' => ['parameters_type' => get_debug_type($request?->parameters ?? null)],
], JSON_THROW_ON_ERROR);

exit($parametersAreAnObject ? 0 : 1);
