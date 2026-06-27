<?php
$url = 'https://nxhcrm.mme.vn/rest/people';
$token = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6ImQ1NTdlNDJjLWU1NjMtNDBlNS1iNDc3LTU0NzI5YjMwMjM0NSJ9.eyJzdWIiOiI4OGZhNTZkNi0xOTM5LTRiZWUtYTAzMi0xODYxZmFiMjdlMDAiLCJ0eXBlIjoiQVBJX0tFWSIsIndvcmtzcGFjZUlkIjoiODhmYTU2ZDYtMTkzOS00YmVlLWEwMzItMTg2MWZhYjI3ZTAwIiwiaWF0IjoxNzgyNTgxOTYxLCJleHAiOjQ5MzYwOTU1NTksImp0aSI6IjVkNzU3OTQ0LThkNTQtNGY4Yy05ZjliLTdkZjhkOTY0NjM3NyJ9.rcyUfDKaqw6nitN2gAQ6gEQEHS2vEQRpwF5xDKX_Ub4GO5J7ET1BswNKiGfyngVbb5KcKVwtRme7vtSTS75g4g';

$payload = [
    'name' => [
        'firstName' => 'Duy',
        'lastName' => 'Hoang',
    ],
    'emails' => ['primaryEmail' => 'admin222@mme.com.vn'],
    'phones' => ['primaryPhoneNumber' => '+84906220284'],
    'careAbout' => 'NANG_CAO_NANG_LUC_BAN_THAN_BANG_NLP',
    'howSupport' => 'Hỗ trợ kiến thức',
    'contactChannel' => 'ZALO',
    'levelInterest' => 'Chỉ tìm hiểu',
    'currentUrl' => [
        'primaryLinkUrl' => 'https://nguyenxuanhuong.com/zzztest/',
        'primaryLinkLabel' => 'Current URL'
    ],
    'referrerUrl' => [
        'primaryLinkUrl' => 'https://example.com/referrer',
        'primaryLinkLabel' => 'Referrer URL'
    ],
    'startedAt' => date('c')
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpcode\n";
echo "Response: $response\n";
