<?php
$default_option = [
    // ─── VAPID ───
    'vapid_public'  => 'BAqETm_cLHiSgmwg-dTfL9-qXU20Mpy41p2_-ZCDc_PE7wotRVex4Jacly9uiAkJtbtuJDwj5gzeww_869Iq5U8',
    'vapid_private' => 'Kff45_imZEaTFZ2-ODGMXQ09DoTKogJcTrty0yxvU4Y',
    'vapid_subject' => 'mailto:admin@x5hd.ru',

    // ─── S3 ───
    's3_enabled'    => 'N',
    's3_endpoint'   => '',
    's3_region'     => 'ru1',
    's3_bucket'     => '',
    's3_access_key' => '',
    's3_secret_key' => '',
    's3_prefix'     => '',
    's3_path_style' => 'N',

    // ─── CDN ───
    'cdn_base'         => 'https://cdn.x5hd.ru',
    // Убирать /<bucket>/ из пути картинки (S3-раздача включает bucket в pathname)
    'cdn_strip_bucket' => 'Y',
    // Убирать /upload/ из пути (CDN смотрит в корень бакета → /iblock/…)
    'cdn_strip_upload' => 'N',

    // ─── CORS ───
    'allowed_origins' => "https://v12.x5hd.ru\nhttps://v8.x5hd.ru\nhttps://x5hd.ru",

    // ─── ID инфоблоков ───
    'ib_general_lectures' => '0',
    'ib_charging'         => '0',
    'ib_theme_lectures'   => '0',
    'ib_tasks'            => '0',
    'ib_themes'           => '0',
    'ib_quiz_questions'   => '0',
    'ib_habits'           => '0',
    'ib_today_widgets'    => '0',
    'ib_tutorial'         => '0',

    // ─── Интервал статистики ───
    'stats_interval_sec' => '600',

    // ─── Служебное ───
    'ib_setup_done'       => 'N',
    'hl_setup_version'    => '0',
    'scheduler_last_run'  => '0',
    'last_dump_at'        => '0',
    'last_stats_at'       => '0',
];