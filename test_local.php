<?php
$url = 'https://nguyenxuanhuong.com/wp-json/mme-form/v1/submit/34762';
$payload = [
    'fields' => [
        'full_name' => 'Duy Hoang',
        'phone' => '+84906220284',
        'email' => 'admin@mme.com.vn',
        'careAbout' => '* Nâng cao năng lực bản thân bằng NLP',
        'howSupport' => 'Hỗ trợ kiến thức',
        'contactChannel' => 'Zalo',
        'levelInterest' => 'Chỉ tìm hiểu'
    ],
    'sourceUrl' => 'https://example.com',
    'referrerUrl' => 'https://example.com/ref',
    'started_at' => (string) ((time() - 10) * 1000)
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpcode\n";
echo "Response: $response\n";
