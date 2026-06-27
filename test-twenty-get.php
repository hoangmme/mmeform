<?php
$url = 'https://nxhcrm.mme.vn/rest/notes';
$token = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6ImQ1NTdlNDJjLWU1NjMtNDBlNS1iNDc3LTU0NzI5YjMwMjM0NSJ9.eyJzdWIiOiI4OGZhNTZkNi0xOTM5LTRiZWUtYTAzMi0xODYxZmFiMjdlMDAiLCJ0eXBlIjoiQVBJX0tFWSIsIndvcmtzcGFjZUlkIjoiODhmYTU2ZDYtMTkzOS00YmVlLWEwMzItMTg2MWZhYjI3ZTAwIiwiaWF0IjoxNzgyNTgxOTYxLCJleHAiOjQ5MzYwOTU1NTksImp0aSI6IjVkNzU3OTQ0LThkNTQtNGY4Yy05ZjliLTdkZjhkOTY0NjM3NyJ9.rcyUfDKaqw6nitN2gAQ6gEQEHS2vEQRpwF5xDKX_Ub4GO5J7ET1BswNKiGfyngVbb5KcKVwtRme7vtSTS75g4g';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$data = json_decode($response, true);
if (isset($data['data']['notes'][0])) {
    echo implode(", ", array_keys($data['data']['notes'][0])) . "\n";
} else {
    echo "No notes found\n";
}
