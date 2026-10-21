<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

if (!$USER->IsAdmin()) return [];

return [
    [
        'parent_menu' => 'global_menu_services',
        'section'     => 'x5_pwa',
        'sort'        => 300,
        'text'        => 'PWA API',
        'title'       => 'Управление PWA-приложением',
        'icon'        => 'sender_menu_icon',
        'page_icon'   => 'sender_page_icon',
        'items_id'    => 'menu_x5_pwa',
        'items'       => [
            [
                'text' => 'Пользователи',
                'url'  => 'x5_pwa_users.php?lang=' . LANGUAGE_ID,
                'more_url' => ['x5_pwa_user_detail.php'],
            ],
            [
                'text' => 'Рефлексии',
                'url'  => 'x5_pwa_reflections.php?lang=' . LANGUAGE_ID,
            ],
            [
                'text' => 'Шагомер',
                'url'  => 'x5_pwa_steps.php?lang=' . LANGUAGE_ID,
            ],
            [
                'text' => 'Общая статистика',
                'url'  => 'x5_pwa_stats.php?lang=' . LANGUAGE_ID,
            ],
            [
                'text' => 'Отправить PUSH',
                'url'  => 'x5_pwa_push.php?lang=' . LANGUAGE_ID,
            ],
        ],
    ],
];