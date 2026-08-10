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
        ]
    ]
];
