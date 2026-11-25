<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature flags (fail closed: everything is off by default)
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) env('CONTENT_STUDIO_ENABLED', false),
    'autopublish_enabled' => (bool) env('CONTENT_AUTOPUBLISH_ENABLED', false),

    // Emergency kill switch from the environment. The admin API can also pause
    // automation at runtime (content_settings.automation_paused).
    'automation_paused' => (bool) env('CONTENT_AUTOMATION_PAUSED', false),

    /*
    |--------------------------------------------------------------------------
    | Scheduler scope
    |--------------------------------------------------------------------------
    | all     - social:* and content:* (default, keeps existing behaviour)
    | content - only content:* (used by the content-scheduler container so it
    |           does not switch on the social:* schedule that is currently off)
    | social  - only social:*
    */
    'schedule_scope' => env('CONTENT_SCHEDULE_SCOPE', 'all'),

    'timezone' => 'Asia/Ho_Chi_Minh',

    'channel' => [
        'platform' => 'tiktok',
        'handle' => env('CONTENT_CHANNEL_HANDLE', 'jerrybadminton'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | `media` uses its own connection (config/queue.php) with a longer
    | retry_after so that long video jobs are not handed out twice.
    */
    'queues' => [
        'connection' => 'redis',
        'default' => 'content',
        'media_connection' => 'redis-media',
        'media' => 'content-media',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline steps
    |--------------------------------------------------------------------------
    */
    'steps' => [
        'max_attempts' => 3,
        'lease_seconds' => 300,
        // Added on top of a job timeout when computing a lease.
        'lease_grace_seconds' => 60,
    ],

    'recover' => [
        // An unfinished run that has been idle this long gets its next job re-sent.
        'stale_after_minutes' => 10,

        // Disks a step may name in input_json.expected_output (see StepOutputProbe).
        'probe_disks' => ['s3', 'local', 'public'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily content mix (docs: plan §2.1). The planner never pads to reach a quota.
    |--------------------------------------------------------------------------
    | max = upper bound per plan date. A slot that cannot be produced with good
    | quality is reported as a shortfall, not filled with weak content.
    */
    'slots' => [
        'news' => ['max' => 1, 'publish_at' => '11:30'],
        'equipment' => ['max' => 1, 'publish_at' => '12:30'],
        'tutorial' => ['max' => 1, 'publish_at' => '17:30'],
        'meme' => ['max' => 1, 'publish_at' => '20:30'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI (Responses API). Fail closed: disabled until explicitly enabled AND a key exists.
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'enabled' => (bool) env('CONTENT_AI_ENABLED', false),
        // Model ids are NOT hard-coded in code; verify them in your OpenAI project.
        'models' => [
            'planner' => env('OPENAI_MODEL_PLANNER', 'gpt-6-luna'),
            'editor' => env('OPENAI_MODEL_EDITOR', 'gpt-6.1-sol'),
            // Media roles. Empty = not configured (nothing calls them yet; image/video generation is a later phase).
            'image' => env('OPENAI_MODEL_IMAGE') ?: null,
            'video' => env('OPENAI_MODEL_VIDEO') ?: null,
        ],
        'timeout_seconds' => (int) env('OPENAI_HTTP_TIMEOUT_SEC', 90),
        'max_output_tokens' => (int) env('CONTENT_AI_MAX_OUTPUT_TOKENS', 4000),
        'max_calls_per_run' => (int) env('CONTENT_AI_MAX_CALLS_PER_RUN', 12),
        'max_retries' => 2,

        // Application-side budget (OpenAI Platform limits are separate and still needed).
        'daily_budget_usd' => (float) env('CONTENT_AI_DAILY_BUDGET_USD', 0.50),
        // Used when prices are unknown, so the guard never silently becomes a no-op.
        'daily_token_budget' => (int) env('CONTENT_AI_DAILY_TOKEN_BUDGET', 300000),
        // USD per 1M tokens. Leave null until you have checked current pricing.
        'pricing' => [
            // An EMPTY value (`OPENAI_PRICE_INPUT_PER_MTOK=`) means "not set", never "0 dollars".
            'input_per_mtok' => ($p = env('OPENAI_PRICE_INPUT_PER_MTOK')) === null || $p === '' ? null : (float) $p,
            'output_per_mtok' => ($p = env('OPENAI_PRICE_OUTPUT_PER_MTOK')) === null || $p === '' ? null : (float) $p,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Editorial limits enforced in code (Structured Outputs only checks the shape)
    |--------------------------------------------------------------------------
    */
    'editorial' => [
        'title_max' => 100,
        'hook_max' => 120,
        'caption_max' => 2200,
        'hashtags_min' => 3,
        'hashtags_max' => 5,
        'hashtag_max_length' => 30,
        'slides_min' => 4,
        'slides_max' => 6,
        'slide_heading_max' => 60,
        'slide_body_max' => 220,
        // Similarity (0..1 token overlap) above which a title counts as a repeat.
        'duplicate_similarity' => 0.8,
        'duplicate_window_days' => 30,
        // Claims the skill forbids without evidence (matched case-insensitively, accent-insensitive).
        'forbidden_phrases' => [
            'đã test', 'mình đã dùng', 'mình đã thử', 'tôi đã dùng', 'tốt nhất', 'số 1', 'số một',
            'chắc chắn viral', 'chắc chắn thắng', 'cam kết', '100%', 'hàng đầu thế giới',
            'chữa', 'điều trị', 'hết đau', 'ngừa chấn thương',
        ],
        // Only legitimate for news built on a verified source.
        'news_only_phrases' => ['breaking', 'tin nóng', 'vừa xác nhận'],
        // Set CONTENT_DISCLOSURE_REQUIRED=true when posts carry affiliate / own-brand promotion.
        'disclosure_required' => (bool) env('CONTENT_DISCLOSURE_REQUIRED', false),
        'disclosure_text' => env('CONTENT_DISCLOSURE_TEXT', '#quangcao'),
        // Topics the planner may rotate through when no verified source drives the slot.
        'equipment_topics' => [
            'Vợt 3U hay 4U: chọn theo lực tay và trình độ',
            'Cách đọc thông số vợt cầu lông (trọng lượng, điểm cân bằng, độ cứng)',
            'Điểm cân bằng nặng đầu hay nhẹ đầu: khác nhau thế nào khi chơi',
            'Lỗi hay gặp khi chọn vợt đầu tiên',
            'Chọn vợt theo ngân sách: nên ưu tiên điều gì',
            'Độ cứng thân vợt: người mới nên chọn thế nào',
        ],
        'tutorial_topics' => [
            'Smash: 3 lỗi phổ biến làm mất lực',
            'Backhand clear cho người mới',
            'Bước chân (footwork) về vị trí giữa sân',
            'Đánh đôi: quy tắc xoay vị trí cơ bản',
            'Cầm vợt đúng cách và chuyển cầm vợt',
            'Phát cầu thấp: sai lầm thường gặp',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | News discovery. Only feeds listed here are ever fetched (allow-list).
    |--------------------------------------------------------------------------
    | Checked on 2026-11-07: bwfbadminton.com answers 403 and its /feed/ is an empty
    | "fansite" feed, so there is NO official BWF feed here. Add feeds you are
    | allowed to use. trust: official | press.
    */
    'news' => [
        'feeds' => [
            ['name' => 'Badminton Asia', 'url' => 'https://www.badmintonasia.org/feed/', 'trust' => 'official'],
        ],
        'recent_days' => (int) env('CONTENT_NEWS_RECENT_DAYS', 7),
        // Items older than this are not even stored (a feed backlog is not news).
        'retention_days' => 30,
        'min_summary_chars' => 80,
        'max_items_per_feed' => 20,
        'fetch' => [
            'timeout_seconds' => 15,
            'max_bytes' => 2 * 1024 * 1024,
            'max_redirects' => 3,
            'user_agent' => 'JBAM-ContentStudio/1.0 (+https://jbam.id.vn)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Equipment
    |--------------------------------------------------------------------------
    | products.price / stock come from a retailer import and age quickly: a price is
    | shown only when it is younger than price_max_age_days. Specs are only ever
    | taken from the product's own data; the planner may not invent any.
    */
    /*
    |--------------------------------------------------------------------------
    | Racket specs from manufacturers' own sites (content:import-specs)
    |--------------------------------------------------------------------------
    | Checked 08/11/2026: both robots.txt files allow product pages. Li-Ning (en.lining.com)
    | answers with a Cloudflare challenge, which we do not work around, so it is not listed.
    */
    'specs' => [
        'delay_seconds' => 3,
        'brands' => [
            'yonex' => [
                'host' => 'www.yonex.com',
                'sitemaps' => ['https://www.yonex.com/sitemap.xml'],
                // product pages are one path segment, e.g. /astrox-99-pro-ax99-p
                'path_pattern' => '#^/[a-z0-9-]+$#',
            ],
            'victor' => [
                'host' => 'www.victorsport.com',
                'sitemaps' => ['https://www.victorsport.com/sitemap_products.xml'],
                'path_pattern' => '#^/product/#',
            ],
        ],
    ],

    'equipment' => [
        'price_max_age_days' => 7,
        // Facts (weight, balance, flex, length, material) required before a slide may talk about one product.
        'min_facts_for_product_mode' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Carousel rendering
    |--------------------------------------------------------------------------
    */
    'render' => [
        // gd (runs in content-queue, no extra image) | playwright (content-media container)
        'driver' => env('CONTENT_RENDER_DRIVER', 'gd'),
        'width' => 1080,
        'height' => 1920,
        'fonts' => [
            'regular' => resource_path('fonts/BeVietnamPro-Regular.ttf'),
            'bold' => resource_path('fonts/BeVietnamPro-Bold.ttf'),
        ],
        'python' => env('CONTENT_MEDIA_PYTHON', 'python3'),
        'script' => env('CONTENT_MEDIA_RENDER_SCRIPT', base_path('content-media/render_carousel.py')),
        'timeout_seconds' => 300,
    ],

    'storage' => [
        // Where generated slides / manifests / export packages are written.
        // `?:` so an empty CONTENT_STORAGE_DISK= falls back instead of naming a disk called ""
        'disk' => env('CONTENT_STORAGE_DISK') ?: env('FILESYSTEM_DISK', 'local'),
        'prefix' => 'content-studio',
    ],
];
