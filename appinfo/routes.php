<?php

return [
    'routes' => [
        ['name' => 'admin#removeComponent', 'url' => '/admin/components/remove', 'verb' => 'POST'],
        ['name' => 'admin#saveSettings', 'url' => '/admin/settings', 'verb' => 'POST'],
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET'
        ],
        [
            'name' => 'validation#validate',
            'url' => '/validate',
            'verb' => 'POST'
        ],
        [
            'name' => 'admin#status',
            'url' => '/admin/status',
            'verb' => 'GET'
        ],
        [
            'name' => 'admin#install',
            'url' => '/admin/install',
            'verb' => 'POST'
        ],
        [
            'name' => 'admin#savePaths',
            'url' => '/admin/paths',
            'verb' => 'POST'
        ]
    ]
];
