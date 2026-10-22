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
                'text'     => 'Пользователи',
                'url'      => 'x5_pwa_users.php?lang=' . LANGUAGE_ID,
                'more_url' => ['x5_pwa_user_detail.php'],
                'icon'     => 'user_menu_icon',
            ],
            [
                'text' => 'Рефлексии',
                'url'  => 'x5_pwa_reflections.php?lang=' . LANGUAGE_ID,
                'icon' => 'learning_menu_icon',
            ],
            [
                'text' => 'Шагомер',
                'url'  => 'x5_pwa_steps.php?lang=' . LANGUAGE_ID,
                'icon' => 'update_menu_icon',
            ],
            [
                'text'     => 'Техподдержка',
                'url'      => 'x5_pwa_support.php?lang=' . LANGUAGE_ID,
                'more_url' => ['x5_pwa_support_detail.php'],
                'icon'     => 'support_menu_icon',
            ],
            [
                'text' => 'Награды',
                'url'  => 'x5_pwa_rewards.php?lang=' . LANGUAGE_ID,
                'icon' => 'statistic_menu_icon',
            ],
            [
                'text' => 'Общая статистика',
                'url'  => 'x5_pwa_stats.php?lang=' . LANGUAGE_ID,
                'icon' => 'statistic_menu_icon',
            ],
            [
                'text' => 'Отправить PUSH',
                'url'  => 'x5_pwa_push.php?lang=' . LANGUAGE_ID,
                'icon' => 'sender_menu_icon',
            ],
        ],
    ],
];