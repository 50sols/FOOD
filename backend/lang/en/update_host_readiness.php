<?php

return [
    'checks' => [
        'sodium_signature' => [
            'label' => 'PHP Sodium (Ed25519 signature prerequisite)',
            'help' => 'Ask your cPanel host to enable PHP sodium with detached-signature verification. This checks only PHP runtime support, not trusted publisher keys, signed packages or the update verifier. ZIP updates remain blocked until signature enforcement is actually integrated and tested.',
        ],
        'upload_temporary' => [
            'label' => 'PHP upload temporary directory',
            'help' => 'The website PHP account needs a writable PHP upload temporary directory. Review upload_tmp_dir, open_basedir, disk space and upload limits in cPanel; a read-only directory check is not proof that a large ZIP upload can complete. Never make the directory world-writable.',
        ],
    ],
];
