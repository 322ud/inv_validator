<?php

return [
    'routes' => [
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
