<?php

return [
    'checks' => [
        'sodium_signature' => [
            'label' => 'PHP Sodium (Ed25519 signature prerequisite)',
            'help' => 'Ask your cPanel host to enable PHP sodium with Ed25519 detached-signature verification. The updater also requires an independently verified publisher key pinned on this host. This read-only check does not certify a signed ZIP, the publisher, or a successful recovery.',
        ],
        'process_execution' => [
            'label' => 'PHP child-process execution for backup and restore',
            'help' => 'Ask your cPanel host to allow the website PHP account to run the controlled database backup/restore process (proc_open/proc_close). A disabled PHP function blocks safe ZIP updates. Do not enable unrestricted shell access or run Composer or Artisan manually on production; this precheck executes nothing.',
        ],
        'backup_cli_tools' => [
            'label' => 'Database backup and recovery client binaries',
            'help' => 'For MySQL/MariaDB, ask the hosting provider to expose executable mysqldump and mysql clients to PHP; PostgreSQL needs pg_dump and pg_restore. Confirm the database account can create and restore full backups, available disk space, and writable private backup storage. A detected executable is not proof of restore permissions. Test an isolated backup and restore before an update.',
        ],
        'upload_temporary' => [
            'label' => 'PHP upload temporary directory',
            'help' => 'The website PHP account needs a writable PHP upload temporary directory. Review upload_tmp_dir, open_basedir, disk space and upload limits in cPanel; a read-only directory check is not proof that a large ZIP upload can complete. Never make the directory world-writable.',
        ],
    ],
];
