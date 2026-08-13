<?php

return [
    /*
    | OpenSearch / Elasticsearch connection
    | AWS OpenSearch example host:
    |   https://search-jb-xxxxx.ap-southeast-2.es.amazonaws.com:443
    */
    'host' => env('ELASTICSEARCH_PORT') && env('ELASTICSEARCH_SCHEME')
        ? env('ELASTICSEARCH_SCHEME').'://'.env('ELASTICSEARCH_HOST').':'.env('ELASTICSEARCH_PORT')
        : env('ELASTICSEARCH_HOST'),
    'user' => env('ELASTICSEARCH_USER'),
    'password' => env('ELASTICSEARCH_PASSWORD', env('ELASTICSEARCH_PASS')),
    'cloud_id' => env('ELASTICSEARCH_CLOUD_ID', env('ELASTICSEARCH_API_ID')),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'ssl_verification' => env('ELASTICSEARCH_SSL_VERIFICATION', true),
    'queue' => [
        'timeout' => env('SCOUT_QUEUE_TIMEOUT'),
    ],
    'indices' => [
        'mappings' => [
            'default' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                ],
            ],
            'products' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'name' => [
                        'type' => 'text',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
                        ],
                    ],
                    'brand' => [
                        'type' => 'text',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 128],
                        ],
                    ],
                    'category' => ['type' => 'keyword'],
                    'description' => ['type' => 'text'],
                    'price' => ['type' => 'integer'],
                    'sold_count' => ['type' => 'integer'],
                    'featured' => ['type' => 'boolean'],
                    'rating' => ['type' => 'float'],
                    'stock' => ['type' => 'integer'],
                ],
            ],
        ],
        'settings' => [
            'default' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 1,
            ],
            'products' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 1,
            ],
        ],
    ],
];
