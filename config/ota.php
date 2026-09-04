<?php

return [
    'auth' => [
        'publisher_group' => env('AUTHENTIK_PUBLISHER_GROUP', 'Rendezvous Entertainment Content Managers'),
    ],
    'content' => [
        'owner' => env('GITHUB_CONTENT_OWNER', 'KSP2Redux'),
        'repo' => env('GITHUB_CONTENT_REPO', 'Content'),
        'branch' => env('GITHUB_CONTENT_BRANCH', 'main'),
        'raw_url' => env('GITHUB_CONTENT_RAW_URL', 'https://raw.githubusercontent.com/KSP2Redux/Content/main'),
    ],
    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    ],
    'weblate' => [
        'url' => env('WEBLATE_URL', 'https://translate.rendezvous.dev/api'),
        'web_url' => env('WEBLATE_WEB_URL', 'https://translate.rendezvous.dev'),
        'token' => env('WEBLATE_TOKEN'),
        'project' => env('WEBLATE_PROJECT', 'ksp2redux'),
        'missions_component' => env('WEBLATE_MISSIONS_COMPONENT', 'missions'),
        'timeout_seconds' => (int) env('WEBLATE_TIMEOUT_SECONDS', 600),
    ],
    'gitlab' => [
        'url' => env('GITLAB_URL', 'https://git.rendezvous.dev/api/v4'),
        'token' => env('GITLAB_TOKEN'),
        'project_id' => env('GITLAB_PROJECT_ID', '9'),
        'ref' => env('GITLAB_OTA_REF', 'develop'),
        'job' => env('GITLAB_OTA_JOB', 'publish-ota-content'),
        'timeout_seconds' => (int) env('GITLAB_TIMEOUT_SECONDS', 1800),
    ],
    'limits' => ['vessel' => 10 * 1024 * 1024, 'mission' => 2 * 1024 * 1024, 'change_set' => 50 * 1024 * 1024],
    'compatibility_path' => resource_path('compatibility/ota-authoring.json'),
];
