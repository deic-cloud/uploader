<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'Page#index',    'url' => '/',                'verb' => 'GET'],
		['name' => 'Upload#upload', 'url' => '/upload',          'verb' => 'POST'],
		['name' => 'Upload#cancel', 'url' => '/upload/cancel',   'verb' => 'POST'],
		['name' => 'Share#share',   'url' => '/share',           'verb' => 'POST'],
		['name' => 'Settings#save', 'url' => '/settings',        'verb' => 'POST'],
		['name' => 'Settings#get',  'url' => '/settings',        'verb' => 'GET'],
	],
];
