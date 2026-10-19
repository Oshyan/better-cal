<?php

declare(strict_types=1);

use BetterCal\Domain\MailPolicy;
use BetterCal\Infra\Db;

return static function (Db $db): string {
    return $db->tx(static function () use ($db): string {
        // The ingest mailbox belongs to the installation's first account, as
        // in worker.php. Receipts have never carried a user_id.
        $user = $db->one('SELECT id, settings_json FROM users ORDER BY id LIMIT 1' . $db->forUpdate());
        if ($user === null) return 'No account needs email sender migration.';
        $settings = json_decode((string) ($user['settings_json'] ?? '{}'), true) ?: [];
        if (!empty($settings['mailSenderMigrationDone'])) return 'Email sender preferences already migrated.';
        $rules = $settings['mailApprovedSenders'] ?? [];
        foreach ($db->all("SELECT DISTINCT from_addr FROM mail_ingest WHERE outcome IN ('created', 'updated')") as $row) {
            $address = MailPolicy::address((string) ($row['from_addr'] ?? ''));
            if ($address !== null) $rules[] = $address;
        }
        $settings['mailApprovedSenders'] = array_values(array_unique($rules));
        $settings['mailEventMode'] ??= 'approved';
        $settings['mailSenderMigrationDone'] = true;
        $db->update('users', ['settings_json' => json_encode($settings)], 'id = ?', [(int) $user['id']]);
        return 'Successful senders in retained email history are approved as exact addresses.';
    });
};
