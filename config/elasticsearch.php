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
                    // Whitespace + word_delimiter so "Li-Ning" also indexes as "lining".
                    'name' => [
                        'type' => 'text',
                        'analyzer' => 'jb_product',
                        'search_analyzer' => 'jb_product_search',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
                        ],
                    ],
                    'brand' => [
                        'type' => 'text',
                        'analyzer' => 'jb_product',
                        'search_analyzer' => 'jb_product_search',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 128],
                        ],
                    ],
                    'category' => ['type' => 'keyword'],
                    'description' => ['type' => 'text', 'analyzer' => 'jb_folded'],
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
                // Single-node clusters stay yellow forever with replicas > 0.
                'number_of_replicas' => (int) env('ELASTICSEARCH_REPLICAS', 0),
            ],
            'products' => [
                'number_of_shards' => 1,
                'number_of_replicas' => (int) env('ELASTICSEARCH_REPLICAS', 0),
                // Ceiling for `from + size`; also caps track_total_hits in ProductService.
                'max_result_window' => 50000,
                'analysis' => [
                    'filter' => [
                        // Keep "Li-Ning" as one token long enough to split AND catenate → "lining".
                        'jb_word_delim' => [
                            'type' => 'word_delimiter_graph',
                            'generate_word_parts' => true,
                            'generate_number_parts' => true,
                            'catenate_words' => true,
                            'catenate_numbers' => true,
                            'catenate_all' => true,
                            'split_on_case_change' => true,
                            'split_on_numerics' => true,
                            'preserve_original' => true,
                        ],
                        // Brand / common-misspelling aliases (expand list as needed).
                        'jb_brand_synonyms' => [
                            'type' => 'synonym',
                            // Single-token aliases only — multi-word synonyms + word_delimiter
                            // produce invalid token offsets at index time.
                            'synonyms' => [
                                'li-ning, lining',
                                'yonex, yonx',
                                'kawasaki, kawashaki',
                            ],
                        ],
                    ],
                    'analyzer' => [
                        // Accent-insensitive for description / free text.
                        'jb_folded' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                        // Index: "Li-Ning" → li, ning, lining (no synonyms — avoids offset errors).
                        'jb_product' => [
                            'type' => 'custom',
                            'tokenizer' => 'whitespace',
                            'filter' => [
                                'lowercase',
                                'asciifolding',
                                'jb_word_delim',
                            ],
                        ],
                        // Search: expand aliases then split/catenate so "lining" hits "Li-Ning".
                        'jb_product_search' => [
                            'type' => 'custom',
                            'tokenizer' => 'whitespace',
                            'filter' => [
                                'lowercase',
                                'asciifolding',
                                'jb_brand_synonyms',
                                'jb_word_delim',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
