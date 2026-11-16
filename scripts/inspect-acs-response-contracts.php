<?php
declare(strict_types=1);
// Read installed source locally; emit schema keys and code markers only.
// No include/eval of installed APIs, no HTTP requests, no string values/secrets.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root = $argv[1] ?? '/var/www/gacs';
$paths = ['api/get-devices-ixc-batch.php', 'api/discovery-ixc-tr069.php',
    'assets/js/jrc-sinal.js', 'assets/js/dashboard-source-policy.js', 'assets/js/devices.js'];
$publicKeys = ['success','message','data','devices','items','rows','result','results','count','total',
    'serial','serial_number','mac','mac_address','device_id','id','name','nome','ip','ip_address',
    'rx','tx','rx_power','tx_power','ixc_rx_power','ixc_tx_power','temperature','temperatura',
    'voltage','voltagem','status','source','cached','cached_at','cache_stale','pagination','hasMore',
    'limit','skip','olt','pon','pon_id','onu','onu_number','slot','model','manufacturer','product_class',
    'pppoe_username','customer_name','ixc_customer_name','online','offline','total_ixc','total_acs',
    'matched','matched_count','optical','last_update','timestamp','generated_at','summary'];
foreach ($paths as $relative) {
    $file = $root . '/' . $relative;
    if (!is_readable($file)) { echo $relative . " MISSING\n"; continue; }
    $text = (string)file_get_contents($file);
    $info = ['file'=>$relative, 'keys'=>[], 'responses'=>[], 'assignments'=>[]];
    if (str_ends_with($relative, '.php')) {
        $tokens = token_get_all($text);
        $significant = array_values(array_filter($tokens, static function($t) {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true);
        }));
        foreach ($significant as $i => $token) {
            if (!is_array($token)) continue;
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = substr($token[1], 1, -1);
                $next = $significant[$i + 1] ?? null;
                if (in_array($key, $publicKeys, true) && is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    $info['keys'][] = ['line'=>$token[2], 'key'=>$key];
                }
            }
            if ($token[0] === T_STRING && in_array(strtolower($token[1]), ['jsonresponse','json_encode'], true)) {
                $expr = '';
                for ($j = $i; $j < min(count($significant), $i + 18); $j++) {
                    $part = $significant[$j];
                    if (is_array($part)) {
                        $expr .= in_array($part[0], [T_STRING,T_VARIABLE], true) ? $part[1] : '<value>';
                    } else $expr .= $part;
                }
                $info['responses'][] = ['line'=>$token[2], 'shape'=>$expr];
            }
            if ($token[0] === T_VARIABLE && in_array($token[1], ['$response','$devices','$data','$result','$out','$items'], true)) {
                if (($significant[$i + 1] ?? '') === '=') $info['assignments'][] = ['line'=>$token[2], 'variable'=>$token[1]];
            }
        }
    } else {
        // Only public field access names, never JavaScript literal values.
        preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\.([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) if (in_array($m[2], $publicKeys, true)) $info['keys'][] = $m[1].'.'.$m[2];
        $info['keys'] = array_values(array_unique($info['keys']));
    }
    echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
