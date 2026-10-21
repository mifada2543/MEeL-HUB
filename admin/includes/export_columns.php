<?php
/**
 * @return array<int, array{key: string, label: string, default?: string, type?: string}>
 */
function meel_export_cols_upload_queue(): array
{
    return [
        ['key' => 'id',         'label' => 'ID',     'type' => 'int'],
        ['key' => 'username',   'label' => 'User',   'default' => '—'],
        ['key' => 'url',        'label' => 'URL',    'default' => ''],
        ['key' => 'media_type', 'label' => 'Tipe',   'default' => ''],
        ['key' => 'status',     'label' => 'Status', 'default' => ''],
        ['key' => 'created_at', 'label' => 'Waktu'],
    ];
}

/**
 * @return array<int, array{key: string, label: string, default?: string, type?: string}>
 */
function meel_export_cols_activity_log(): array
{
    return [
        ['key' => 'id',         'label' => 'ID',         'type' => 'int'],
        ['key' => 'user_id',    'label' => 'User ID',    'default' => '',   'type' => 'int?'],
        ['key' => 'username',   'label' => 'Username',   'default' => 'Tamu'],
        ['key' => 'action',     'label' => 'Action'],
        ['key' => 'media_type', 'label' => 'Media Type', 'default' => ''],
        ['key' => 'media_id',   'label' => 'Media ID',   'default' => '',   'type' => 'int?'],
        ['key' => 'ip_address', 'label' => 'Alamat IP',  'default' => ''],
        ['key' => 'created_at', 'label' => 'Waktu'],
    ];
}
