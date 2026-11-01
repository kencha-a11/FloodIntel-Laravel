<?php

test('the unauthenticated mail-debug route does not exist', function () {
    $this->get('/test-email')->assertNotFound();
});
