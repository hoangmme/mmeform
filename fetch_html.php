<?php
$html = file_get_contents('https://nguyenxuanhuong.com/zzztest/');
preg_match_all('/<select[^>]*name="fields\[careAbout\]"[^>]*>(.*?)<\/select>/s', $html, $matches);
if (!empty($matches[1][0])) {
    preg_match_all('/<option value="([^"]*)">/', $matches[1][0], $options);
    print_r($options[1]);
} else {
    echo "No careAbout select found\n";
    preg_match_all('/<input[^>]*type="radio"[^>]*name="fields\[careAbout\]"[^>]*value="([^"]*)"/', $html, $matches);
    print_r($matches[1]);
}
