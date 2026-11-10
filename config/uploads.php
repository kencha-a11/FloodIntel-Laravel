<?php

/*
 * One declared size ladder for request bodies. The order is the contract:
 *
 * - a photo above `photo_bytes` answers `422` with `photo_size`, which is the
 *   signal the queue's shrink-and-resend branch acts on (D-108);
 * - a body above `request_bytes` answers `413` before routing or validation;
 * - PHP's `upload_max_filesize`/`post_max_size` and nginx's
 *   `client_max_body_size` stay above both (see server/Dockerfile and
 *   docker/nginx/default.conf.template) so the application always answers
 *   first, in its own JSON error contract.
 * - nginx's `client_max_body_size` must not sit above PHP's
 *   `post_max_size`. PHP discards a body that exceeds `post_max_size`
 *   before the application sees it, so a body between the two limits was
 *   answered 200 with an empty body — an oversized upload that looked
 *   accepted (DP-05, 2026-11-10).
 */
return [
    'photo_bytes' => 5 * 1024 * 1024,
    'request_bytes' => 6 * 1024 * 1024,
];
