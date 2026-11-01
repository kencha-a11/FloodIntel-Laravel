<?php

it('exposes the response Date header so a cross-origin client can measure clock skew', function () {
    $exposed = config('cors.exposed_headers');

    expect($exposed)
        ->toContain('Date')
        ->and($exposed)->toContain('X-Report-Count');
});
