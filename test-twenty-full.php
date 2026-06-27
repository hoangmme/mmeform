<?php
$url = 'https://nxhcrm.mme.vn/rest/people';
$token = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6ImQ1NTdlNDJjLWU1NjMtNDBlNS1iNDc3LTU0NzI5YjMwMjM0NSJ9.eyJzdWIiOiI4OGZhNTZkNi0xOTM5LTRiZWUtYTAzMi0xODYxZmFiMjdlMDAiLCJ0eXBlIjoiQVBJX0tFWSIsIndvcmtzcGFjZUlkIjoiODhmYTU2ZDYtMTkzOS00YmVlLWEwMzItMTg2MWZhYjI3ZTAwIiwiaWF0IjoxNzgyNTgxOTYxLCJleHAiOjQ5MzYwOTU1NTksImp0aSI6IjVkNzU3OTQ0LThkNTQtNGY4Yy05ZjliLTdkZjhkOTY0NjM3NyJ9.rcyUfDKaqw6nitN2gAQ6gEQEHS2vEQRpwF5xDKX_Ub4GO5J7ET1BswNKiGfyngVbb5KcKVwtRme7vtSTS75g4g';

$payloads = [
    [
        'name' => ['firstName' => 'Test', 'lastName' => 'User'],
        'emails' => ['primaryEmail' => 'test2@mme.vn'],
        'phones' => ['primaryPhoneNumber' => '+84912345678'],
    ]
];

foreach ($payloads as $i => $payload) {
    echo "Testing payload $i...\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "Status: $code\n";
    echo "Response: $response\n\n";
}
