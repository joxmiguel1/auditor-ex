<?php
// Configuración por defecto
$targetUrl = isset($_GET['url']) ? $_GET['url'] : ''; 
$results = null;

// --- LÓGICA DE HISTORIAL (COOKIES) ---
$history = [];
if (isset($_COOKIE['seo_history'])) {
    $history = json_decode($_COOKIE['seo_history'], true);
    if (!is_array($history)) $history = [];
}

// Función principal de análisis (Tu lógica actual intacta)
function analyzePerformance($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Por favor introduce una URL válida (incluyendo http:// o https://)'];
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); 
    curl_setopt($ch, CURLOPT_NOBODY, false); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; SEOAnalyzerBot/1.0; +http://joxdesign.pro)');
    curl_setopt($ch, CURLOPT_ENCODING, ''); 

    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);

    if (curl_errno($ch)) {
        return ['error' => 'No se pudo conectar al sitio. Verifica la URL. (' . curl_error($ch) . ')'];
    }

    $info = curl_getinfo($ch);
    
    if ($info['http_code'] >= 400) {
        return ['error' => 'El servidor respondió con un error: ' . $info['http_code'] . ' Not Found/Error'];
    }

    $headerSize = $info['header_size'];
    $headerString = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $finalUrl = $info['url']; 

    curl_close($ch);

    // --- ANÁLISIS ---
    
    // Compresión
    $isCompressed = false;
    $compressionType = 'Ninguna';
    if (preg_match('/Content-Encoding:\s*(gzip|br|deflate)/i', $headerString, $matches)) {
        $isCompressed = true;
        $compressionType = strtoupper($matches[1]);
    }

    // Caché
    $hasCacheControl = stripos($headerString, 'Cache-Control:') !== false;
    $hasExpires = stripos($headerString, 'Expires:') !== false;
    $cacheStatus = ($hasCacheControl || $hasExpires) ? "Detectado" : "No detectado";

    // Server
    $serverSoftware = 'Desconocido';
    if (preg_match('/^Server:\s*(.*)$/mi', $headerString, $matches)) {
        $serverSoftware = trim($matches[1]);
    }

    // CDN
    $cdnDetected = false;
    $cdnProvider = 'No detectado';
    $cdnMethod = ''; 
    
    $cdnHeaders = [
        'cf-ray' => 'Cloudflare',
        'x-amz-cf-id' => 'Amazon CloudFront',
        'x-fastly-request-id' => 'Fastly',
        'x-sucuri-id' => 'Sucuri',
        'x-azure-ref' => 'Azure CDN',
        'x-akamai-request-id' => 'Akamai',
        'incap-ses' => 'Imperva Incapsula',
        'x-cdn' => 'Generic CDN',
        'x-qc-pop' => 'QUIC.cloud',
        'x-qc-cache' => 'QUIC.cloud'
    ];

    foreach ($cdnHeaders as $header => $provider) {
        if (stripos($headerString, $header . ':') !== false) {
            $cdnDetected = true;
            $cdnProvider = $provider;
            $cdnMethod = 'Header';
            break;
        }
    }

    if (!$cdnDetected) {
        if (preg_match('/Server:\s*(Cloudflare|AkamaiGHost|KeyCDN|Netlify|BunnyCDN|Google Frontend|QUIC\.cloud)/i', $headerString, $matches)) {
            $cdnDetected = true;
            $cdnProvider = $matches[1];
            $cdnMethod = 'Server Header';
        } elseif (stripos($headerString, 'Via:') !== false && stripos($headerString, 'CloudFront') !== false) {
            $cdnDetected = true;
            $cdnProvider = 'Amazon CloudFront';
            $cdnMethod = 'Via Header';
        }
    }

    if (!$cdnDetected) {
        $host = parse_url($finalUrl, PHP_URL_HOST);
        $dnsRecords = @dns_get_record($host, DNS_NS);
        
        if ($dnsRecords) {
            foreach ($dnsRecords as $record) {
                $target = isset($record['target']) ? strtolower($record['target']) : '';
                if (strpos($target, 'cloudflare.com') !== false) { $cdnDetected = true; $cdnProvider = 'Cloudflare (DNS)'; $cdnMethod = 'DNS'; break; } 
                elseif (strpos($target, 'awsdns') !== false) { $cdnDetected = true; $cdnProvider = 'AWS (DNS)'; $cdnMethod = 'DNS'; break; } 
                elseif (strpos($target, 'azure-dns') !== false) { $cdnDetected = true; $cdnProvider = 'Azure (DNS)'; $cdnMethod = 'DNS'; break; }
            }
        }
    }

    $resolveUrl = function($src) use ($finalUrl) {
        // Simple resolver implementation needed for the snippet context
        $baseUrlParts = parse_url($finalUrl);
        $baseScheme = isset($baseUrlParts['scheme']) ? $baseUrlParts['scheme'] : 'http';
        $baseHost = isset($baseUrlParts['host']) ? $baseUrlParts['host'] : '';
        $basePath = isset($baseUrlParts['path']) ? $baseUrlParts['path'] : '/';
        
        if (empty($src)) return '';
        if (strpos($src, '//') === 0) return $baseScheme . ':' . $src;
        if (strpos($src, 'http') === 0) return $src;
        if (strpos($src, 'data:') === 0) return $src;
        if (strpos($src, '/') === 0) return $baseScheme . '://' . $baseHost . $src;
        $dir = rtrim(dirname($basePath), '/');
        return $baseScheme . '://' . $baseHost . $dir . '/' . $src;
    };

    // Minificación
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/i', $body, $jsMatches);
    preg_match_all('/<link[^>]+href=["\']([^"\']+\.css[^"\']*)["\']/i', $body, $cssMatches);

    $jsTotal = 0; $jsMinified = 0; $jsUnminifiedList = [];
    foreach ($jsMatches[1] as $js) {
        if (strpos($js, 'data:') === 0) continue;
        $jsTotal++;
        $fullUrl = $resolveUrl($js);
        $isMinified = false;
        if (strpos($js, '.min.js') !== false) $isMinified = true;
        elseif (strpos($js, '/litespeed/') !== false) $isMinified = true;
        elseif (strpos($js, '/cache/') !== false) $isMinified = true;
        elseif (strpos($js, 'autoptimize') !== false) $isMinified = true;
        elseif (strpos($js, 'siteground-optimizer') !== false) $isMinified = true;

        if ($isMinified) $jsMinified++; else $jsUnminifiedList[] = $fullUrl; 
    }

    $cssTotal = 0; $cssMinified = 0; $cssUnminifiedList = [];
    foreach ($cssMatches[1] as $css) {
        if (strpos($css, 'data:') === 0) continue;
        $cssTotal++;
        $fullUrl = $resolveUrl($css);
        $isMinified = false;
        if (strpos($css, '.min.css') !== false) $isMinified = true;
        elseif (strpos($css, '/litespeed/') !== false) $isMinified = true;
        elseif (strpos($css, '/cache/') !== false) $isMinified = true;
        elseif (strpos($css, 'autoptimize') !== false) $isMinified = true;
        elseif (strpos($css, 'siteground-optimizer') !== false) $isMinified = true;

        if ($isMinified) $cssMinified++; else $cssUnminifiedList[] = $fullUrl; 
    }

    // HTML Parsing
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    @$doc->loadHTML($body);
    libxml_clear_errors();

    $nodes = $doc->getElementsByTagName('title');
    $title = $nodes->length > 0 ? $nodes->item(0)->nodeValue : null;

    $metas = $doc->getElementsByTagName('meta');
    $description = null;
    $wpVersion = null;

    foreach ($metas as $meta) {
        $name = strtolower($meta->getAttribute('name'));
        if ($name == 'description') $description = $meta->getAttribute('content');
        if ($name == 'generator') {
            $content = $meta->getAttribute('content');
            if (stripos($content, 'WordPress') === 0) $wpVersion = $content;
        }
    }

    $h1Count = $doc->getElementsByTagName('h1')->length;

    $imgs = $doc->getElementsByTagName('img');
    $totalImages = $imgs->length;
    $missingAlt = 0;
    $missingAltList = [];

    foreach ($imgs as $img) {
        $src = $img->getAttribute('src');
        if (empty($src) || trim($src) === '') continue;
        $alt = $img->getAttribute('alt');
        if (trim($alt) === '') {
            $missingAlt++;
            $previewSrc = $resolveUrl($src);
            $filename = basename($src) ?: 'imagen-sin-nombre';
            $missingAltList[] = ['src' => $previewSrc, 'filename' => $filename];
        }
    }

    $loadTime = round(($endTime - $startTime) * 1000, 2);

    // Score Calculation
    $score = 0;
    if ($cdnDetected) $score += 20;
    if ($loadTime <= 200) $score += 25; elseif ($loadTime <= 1000) $score += 15; else $score += 5;
    if ($totalImages > 0) {
        $optimizedImages = $totalImages - $missingAlt;
        $score += round(($optimizedImages / $totalImages) * 15);
    } else $score += 15;
    if (!$wpVersion) $score += 5;
    if ($title && strlen($title) >= 30 && strlen($title) <= 65) $score += 5; elseif ($title) $score += 2;
    if ($description && strlen($description) >= 120 && strlen($description) <= 165) $score += 5; elseif ($description) $score += 2;
    if ($h1Count === 1) $score += 5; elseif ($h1Count > 1) $score += 2;
    if ($isCompressed) $score += 5;
    if ($cacheStatus === "Detectado") $score += 5;
    
    if ($jsTotal > 0) { if ($jsMinified === $jsTotal) $score += 5; else $score += floor(($jsMinified / $jsTotal) * 5); } else $score += 5;
    if ($cssTotal > 0) { if ($cssMinified === $cssTotal) $score += 5; else $score += floor(($cssMinified / $cssTotal) * 5); } else $score += 5;

    return [
        'url' => $info['url'],
        'http_code' => $info['http_code'],
        'load_time_ms' => $loadTime,
        'score' => $score, 
        'compression' => $isCompressed,
        'compression_type' => $compressionType,
        'cache_headers' => $cacheStatus,
        'server_info' => $serverSoftware,
        'cdn_detected' => $cdnDetected, 
        'cdn_provider' => $cdnProvider, 
        'cdn_method' => $cdnMethod,
        'js_stats' => ['total' => $jsTotal, 'minified' => $jsMinified, 'list_unminified' => $jsUnminifiedList],
        'css_stats' => ['total' => $cssTotal, 'minified' => $cssMinified, 'list_unminified' => $cssUnminifiedList],
        'seo_title' => $title,
        'seo_desc' => $description,
        'h1_count' => $h1Count,
        'img_stats' => ['total' => $totalImages, 'missing_alt' => $missingAlt, 'list_missing' => $missingAltList],
        'wp_version' => $wpVersion
    ];
}

if (isset($_GET['url']) && !empty($_GET['url'])) {
    $results = analyzePerformance($_GET['url']);
    if (!isset($results['error'])) {
        $newEntry = ['timestamp' => time(), 'url' => $results['url'], 'load_time' => $results['load_time_ms'], 'score' => $results['score'], 'browser' => $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido'];
        array_unshift($history, $newEntry);
        $history = array_slice($history, 0, 10);
        setcookie('seo_history', json_encode($history), time() + (86400 * 30), "/");
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditor EX - JoxDesign</title>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #b384ec;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --info: #0984e3;
            --bg: #dfe6e9; --card: #ffffff; --text: #2d3436; --text-secondary: #636e72; --border: #f1f2f6; --input-bg: #fff; --input-border: #eee; --details-bg: #fafafa; --shadow: rgba(0,0,0,0.05); --code-text: #d63031; --btn-text: #ffffff;
            --tab-bg: #f1f2f6; --tab-active-bg: #fff; --tab-active-color: #6c5ce7;
            /* Color específico para EX en light mode */
            --ex-color: #816698;
        }

        [data-theme="dark"] {
            --bg: #121212; --card: #1E1E1E; --text: #E1E1E1; --text-secondary: #A0A0A0; --border: #333333; --input-bg: #2C2C2C; --input-border: #333333; --details-bg: #252525; --shadow: rgba(0,0,0,0.4);
            --primary: #ffffff; --success: #03DAC6; --warning: #CF6679; --danger: #CF6679; --info: #64B5F6; --code-text: #FF80AB; --btn-text: #000000;
            --tab-bg: #252525; --tab-active-bg: #1E1E1E; --tab-active-color: #ffffff;
            /* Color específico para EX en dark mode */
            --ex-color: #cb2128;
        }

        body { font-family: 'Lato', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 0; min-height: 100vh; display: flex; flex-direction: column; transition: background 0.3s, color 0.3s; }
        
        /* ESTADO INICIAL CENTRADO */
        .container { 
            max-width: 800px; 
            margin: 40px auto; 
            flex: 1; 
            padding: 0 20px; 
            width: 100%; 
            box-sizing: border-box;
            transition: all 0.5s ease;
        }
        
        .container.center-mode {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 80vh; /* Centrado vertical en pantalla vacía */
        }
        
        h1 { text-align: center; color: var(--primary); margin-bottom: 30px; font-weight: 900; transition: all 0.5s ease; }
        /* En modo centro el título puede ser más grande */
        .container.center-mode h1 { font-size: 3em; margin-bottom: 40px; }

        .search-box { 
            display: flex; 
            gap: 10px; 
            background: var(--card); 
            padding: 20px; 
            border-radius: 12px; 
            box-shadow: 0 4px 6px var(--shadow); 
            flex-wrap: wrap; 
            transition: all 0.5s ease;
        }
        /* En modo centro, search box más prominente */
        .container.center-mode .search-box {
            padding: 30px;
            box-shadow: 0 10px 30px var(--shadow);
        }

        input[type="text"] { flex: 1; min-width: 200px; padding: 12px; border: 2px solid var(--input-border); background: var(--input-bg); color: var(--text); border-radius: 6px; font-size: 16px; outline: none; transition: 0.3s; font-family: 'Lato', sans-serif; }
        input[type="text"]:focus { border-color: var(--primary); }
        button { padding: 12px 25px; background: var(--primary); color: var(--btn-text); border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Lato', sans-serif; white-space: nowrap; flex-grow: 1; }
        button:hover { opacity: 0.9; transform: translateY(-1px); }
        button:disabled { cursor: not-allowed; opacity: 0.6; }
        @media (min-width: 600px) { button { flex-grow: 0; } }

        /* ANIMACIÓN DE ENTRADA TIPO ACORDEÓN PARA RESULTADOS */
        .result-card { 
            background: var(--card); 
            margin-top: 25px; 
            border-radius: 12px; 
            padding: 25px; 
            box-shadow: 0 4px 15px var(--shadow); 
            border: 1px solid var(--border); 
            
            /* Animación de apertura */
            animation: slideDownFade 0.7s cubic-bezier(0.25, 0.8, 0.25, 1) forwards;
            transform-origin: top;
        }

        @keyframes slideDownFade {
            from {
                opacity: 0;
                transform: translateY(-20px) scaleY(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scaleY(1);
            }
        }

        .section-title { font-size: 1.1em; font-weight: 700; color: var(--primary); margin: 25px 0 15px 0; padding-bottom: 5px; border-bottom: 2px solid var(--border); text-transform: uppercase; letter-spacing: 1px; }
        
        .result-header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
        .score-container { display: flex; justify-content: center; margin-bottom: 15px; }
        .score-circle-large { width: 90px; height: 90px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5em; font-weight: 900; color: white; box-shadow: 0 6px 15px rgba(0,0,0,0.15); border: 5px solid rgba(255,255,255,0.2); position: relative; }
        @keyframes pulse-green { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(0, 184, 148, 0.7); } 70% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(0, 184, 148, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(0, 184, 148, 0); } }
        .pulse-100 { animation: pulse-green 2s infinite; }

        .domain-title { font-size: 1.6em; color: var(--text); font-weight: 900; margin-bottom: 5px; }
        .status-line { font-size: 1em; color: var(--text-secondary); font-weight: 400; }

        .metric-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .metric-label { font-weight: 700; color: var(--text-secondary); }
        .metric-value { text-align: right; max-width: 60%; color: var(--text); }
        .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.9em; font-weight: bold; display: inline-block; }
        
        .bg-success { background: rgba(0, 184, 148, 0.15); color: var(--success); }
        [data-theme="dark"] .bg-success { background: rgba(3, 218, 198, 0.15); color: var(--success); } 
        .bg-warning { background: rgba(253, 203, 110, 0.15); color: #d4a217; } 
        [data-theme="dark"] .bg-warning { background: rgba(207, 102, 121, 0.15); color: var(--warning); }
        .bg-danger { background: rgba(214, 48, 49, 0.15); color: var(--danger); }
        .bg-info { background: rgba(9, 132, 227, 0.15); color: var(--info); }

        details { background: var(--details-bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; cursor: pointer; margin-top: 5px; color: var(--text-secondary); }
        summary { font-weight: 700; color: var(--text-secondary); outline: none; user-select: none; }
        ul.file-list { margin: 10px 0 0 0; padding-left: 20px; color: var(--text-secondary); word-break: break-all; font-size: 0.85em; }
        ul.file-list li { margin-bottom: 5px; }
        ul.file-list a { color: var(--code-text); text-decoration: none; word-break: break-all; }
        ul.file-list a:hover { text-decoration: underline; }
        
        table.history-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.85em; }
        table.history-table th { text-align: left; padding: 8px; border-bottom: 2px solid var(--border); color: var(--primary); font-weight: 700; }
        table.history-table td { padding: 8px; border-bottom: 1px solid var(--border); color: var(--text-secondary); }
        
        footer { text-align: center; padding: 20px; color: var(--text-secondary); font-size: 0.9em; margin-top: auto; }
        footer a { color: var(--primary); text-decoration: none; font-weight: bold; }

        #theme-toggle { position: fixed; top: 20px; right: 20px; background: var(--card); border: 2px solid var(--border); color: var(--text); padding: 10px; border-radius: 50%; cursor: pointer; box-shadow: 0 2px 10px var(--shadow); z-index: 1000; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; font-size: 1.2em; }
        #theme-toggle:hover { transform: scale(1.1); border-color: var(--primary); }

        /* TABS STYLES MEJORADOS PARA MOVIL */
        .tabs-header { 
            display: flex; 
            gap: 10px; 
            margin-top: 25px; 
            overflow-x: auto; 
            padding-bottom: 5px; 
            -webkit-overflow-scrolling: touch; /* Scroll suave en iOS */
            scrollbar-width: none; /* Ocultar scrollbar en Firefox */
            -ms-overflow-style: none; /* Ocultar scrollbar en IE/Edge */
        }
        .tabs-header::-webkit-scrollbar { 
            display: none; /* Ocultar scrollbar en Chrome/Safari */
        }
        
        .tab-btn { 
            background: var(--tab-bg); 
            color: var(--text-secondary); 
            border: none; 
            padding: 12px 20px; 
            border-radius: 50px; /* Estilo Pastilla */
            cursor: pointer; 
            font-weight: 700; 
            font-family: 'Lato', sans-serif; 
            transition: 0.3s; 
            white-space: nowrap; 
            flex: 1 0 auto; /* Flexible pero no se encoge demasiado */
            text-align: center; 
        }
        
        .tab-btn.active { background: var(--primary); color: var(--btn-text); transform: translateY(-2px); box-shadow: 0 4px 6px var(--shadow); }
        .tab-btn:hover:not(.active) { background: var(--border); }
        .tab-content { display: none; }
        .tab-content.active { display: block; } /* La animación está en .result-card */

        /* PSI Styles */
        .psi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-top: 20px; }
        .psi-metric { background: var(--details-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border); text-align: center; }
        .psi-value { font-size: 1.5em; font-weight: 900; color: var(--text); margin: 5px 0; }
        .psi-label { font-size: 0.85em; color: var(--text-secondary); }
        .psi-grade-green { color: var(--success); } .psi-grade-orange { color: var(--warning); } .psi-grade-red { color: var(--danger); }
        
        .external-tools { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .tool-card { background: var(--details-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border); text-align: center; transition: 0.3s; text-decoration: none; color: var(--text); display: block; }
        .tool-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: 0 5px 15px var(--shadow); }
        .tool-name { font-weight: 900; margin-bottom: 5px; font-size: 1.1em; }
        .tool-desc { font-size: 0.85em; color: var(--text-secondary); }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Spinner Animation */
        .loader { display: inline-block; animation: spin 1s linear infinite; font-size: 1.1em; margin-right: 5px; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

<button id="theme-toggle" aria-label="Cambiar tema" title="Cambiar tema Claro/Oscuro"><span id="theme-icon">☀️</span></button>

<!-- Detectamos si es estado inicial (sin resultados) para centrar -->
<?php $containerClass = (!$results && !isset($results['error'])) ? 'container center-mode' : 'container'; ?>

<div class="<?php echo $containerClass; ?>">
    <h1>Auditor <span style="color: var(--ex-color)">EX</span></h1>
    
    <form method="GET" action="">
        <div class="search-box">
            <input type="text" name="url" placeholder="https://corponet.com" value="<?php echo htmlspecialchars($targetUrl); ?>" required id="targetUrlInput">
            <button type="submit">Analizar</button>
        </div>
    </form>

    <?php if ($results): ?>
        <?php if (isset($results['error'])): ?>
            <div class="result-card" style="text-align: center; padding: 40px;">
                <div style="font-size: 4em; margin-bottom: 15px;">😢</div>
                <h3 style="color: var(--danger); margin-top: 0; font-size: 1.5em;">¡Ups! Algo salió mal</h3>
                <div class="badge bg-danger" style="font-size: 1em; padding: 12px 20px; display: inline-block; margin: 15px 0;"><?php echo htmlspecialchars($results['error']); ?></div>
                <p style="margin-top: 15px; color: var(--text-secondary);">No pudimos acceder a ese sitio. Por favor, verifica que la URL sea correcta e inténtalo de nuevo.</p>
            </div>
        <?php else: ?>
            
            <!-- TABS NAVEGACIÓN -->
            <div class="tabs-header">
                <button class="tab-btn active" onclick="openTab('local')">📍 Auditoría Local</button>
                <button class="tab-btn" onclick="openTab('psi')">🚀 Core Web Vitals</button>
                <button class="tab-btn" onclick="openTab('external')">⚡ Herramientas Externas</button>
            </div>

            <!-- TAB 1: AUDITORÍA LOCAL (PHP) -->
            <div id="tab-local" class="tab-content active">
                <div class="result-card">
                    <div class="result-header">
                        <?php 
                            $score = $results['score'];
                            $scoreColor = '#d63031'; 
                            if ($score >= 80) $scoreColor = '#00b894'; elseif ($score >= 50) $scoreColor = '#fdcb6e';
                            $pulseClass = ($score == 100) ? 'pulse-100' : '';
                        ?>
                        <div class="score-container">
                            <div class="score-circle-large <?php echo $pulseClass; ?>" style="background-color: <?php echo $scoreColor; ?>;" title="Puntuación SEO estimada"><?php echo $score; ?></div>
                        </div>
                        <div class="domain-title"><?php echo parse_url($results['url'], PHP_URL_HOST); ?></div>
                        <div class="status-line">
                            Status: <span class="<?php echo $results['http_code'] == 200 ? 'text-success' : 'text-danger'; ?>" style="font-weight:bold;"><?php echo $results['http_code']; ?></span> 
                            | Tiempo: <?php 
                                $timeClass = 'text-danger';
                                if($results['load_time_ms'] <= 200) $timeClass = 'text-success'; elseif($results['load_time_ms'] <= 1000) $timeClass = 'text-warning';
                            ?>
                            <span class="<?php echo $timeClass; ?>" style="font-weight:bold; color: <?php if($timeClass == 'text-success') echo 'var(--success)'; elseif($timeClass == 'text-warning') echo 'var(--warning)'; else echo 'var(--danger)'; ?>;"><?php echo $results['load_time_ms']; ?> ms</span>
                        </div>
                    </div>

                    <div class="section-title">🔍 Salud SEO & WordPress</div>
                    <div class="metric-row"><span class="metric-label">Versión WordPress</span><div class="metric-value"><?php if ($results['wp_version']): ?><span class="badge bg-warning">Visible (<?php echo htmlspecialchars($results['wp_version']); ?>)</span><div style="font-size:0.75em; color:var(--danger); margin-top:4px;">Riesgo de seguridad: Ocultar versión</div><?php else: ?><span class="badge bg-info">Oculta / No Detectada</span><?php endif; ?></div></div>
                    <div class="metric-row"><span class="metric-label">Etiqueta Title</span><div class="metric-value"><?php if ($results['seo_title']): ?><div style="font-weight:bold; margin-bottom:4px;"><?php echo htmlspecialchars(substr($results['seo_title'], 0, 50)) . '...'; ?></div><span class="badge <?php echo (strlen($results['seo_title']) > 30 && strlen($results['seo_title']) < 65) ? 'bg-success' : 'bg-warning'; ?>"><?php echo strlen($results['seo_title']); ?> caracteres</span><?php else: ?><span class="badge bg-danger">Falta etiqueta Title</span><?php endif; ?></div></div>
                    <div class="metric-row"><span class="metric-label">Meta Description</span><div class="metric-value"><?php if ($results['seo_desc']): ?><span class="badge <?php echo (strlen($results['seo_desc']) > 120 && strlen($results['seo_desc']) < 165) ? 'bg-success' : 'bg-warning'; ?>"><?php echo strlen($results['seo_desc']); ?> caracteres</span><?php else: ?><span class="badge bg-danger">Vacía o No encontrada</span><?php endif; ?></div></div>
                    <div class="metric-row"><span class="metric-label">Encabezados H1</span><div class="metric-value"><?php $h1 = $results['h1_count']; if ($h1 === 1) echo '<span class="badge bg-success">1 (Correcto)</span>'; elseif ($h1 === 0) echo '<span class="badge bg-danger">0 (Falta H1)</span>'; else echo '<span class="badge bg-warning">'. $h1 .' (Múltiples H1)</span>'; ?></div></div>
                    <div style="padding: 12px 0; border-bottom: 1px solid var(--border);"><div style="display: flex; justify-content: space-between; align-items: center;"><span class="metric-label">Imágenes sin Alt</span><div class="metric-value"><?php if ($results['img_stats']['total'] > 0): ?><?php if ($results['img_stats']['missing_alt'] == 0): ?><span class="badge bg-success">Todas optimizadas (<?php echo $results['img_stats']['total']; ?>)</span><?php else: ?><span class="badge bg-warning"><?php echo $results['img_stats']['missing_alt']; ?> de <?php echo $results['img_stats']['total']; ?> sin texto alt</span><?php endif; ?><?php else: ?><span class="badge bg-info">No hay imágenes</span><?php endif; ?></div></div><?php if (!empty($results['img_stats']['list_missing'])): ?><details><summary style="font-size:0.9em; color:var(--warning); margin-top:8px;">Ver imágenes afectadas</summary><ul class="file-list" style="padding-left:0; list-style:none;"><?php foreach($results['img_stats']['list_missing'] as $img): ?><li style="display:flex; align-items:center; gap:10px; margin-top:8px; border-bottom:1px solid var(--border); padding-bottom:5px;"><div style="width:40px; height:40px; background:#eee; border-radius:4px; overflow:hidden; flex-shrink:0; display:flex; align-items:center; justify-content:center; border:1px solid #ddd;"><?php if($img['src']): ?><img src="<?php echo htmlspecialchars($img['src']); ?>" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'"><?php else: ?><span style="font-size:10px; color:#999;">N/A</span><?php endif; ?></div><div style="font-size:0.85em; width: 100%; overflow: hidden;"><a href="<?php echo htmlspecialchars($img['src']); ?>" target="_blank" style="color:var(--primary); text-decoration:none; font-weight:500; display:block; margin-bottom:2px;"><?php echo htmlspecialchars($img['filename']); ?> ↗</a><div style="font-size:0.8em; color:var(--text-secondary); word-break:break-all;"><?php echo htmlspecialchars($img['src']); ?></div></div></li><?php endforeach; ?></ul></details><?php endif; ?></div>

                    <div class="section-title">⚡ Rendimiento Técnico</div>
                    <div class="metric-row"><span class="metric-label">Tiempo de Carga</span><span class="badge <?php echo $results['load_time_ms'] <= 200 ? 'bg-success' : ($results['load_time_ms'] <= 1000 ? 'bg-warning' : 'bg-danger'); ?>"><?php echo $results['load_time_ms']; ?> ms</span></div>
                    <div class="metric-row"><span class="metric-label">CDN (Red de Distribución)</span><?php if ($results['cdn_detected']) { $methodInfo = ($results['cdn_method'] == 'DNS') ? 'DNS Only' : 'Header'; echo '<span class="badge bg-success">Activa (' . htmlspecialchars($results['cdn_provider']) . ' - ' . $methodInfo . ')</span>'; } elseif ($results['server_info'] !== 'Desconocido' && stripos($results['server_info'], 'LiteSpeed') !== false) { echo '<span class="badge bg-warning">No detectada (Servidor: ' . htmlspecialchars($results['server_info']) . ')</span>'; } else { echo '<span class="badge bg-warning">No detectada / Servidor Local</span>'; } ?></div>
                    <div class="metric-row"><span class="metric-label">Compresión (GZIP/Brotli)</span><?php if ($results['compression']): ?><span class="badge bg-success">Activo (<?php echo $results['compression_type']; ?>)</span><?php else: ?><span class="badge bg-danger">Inactivo</span><?php endif; ?></div>
                    <div class="metric-row"><span class="metric-label">Caché de Navegador<small style="color:var(--text-secondary); font-weight:400; margin-left: 5px;">(<?php echo htmlspecialchars($results['server_info']); ?>)</small></span><span class="badge <?php echo $results['cache_headers'] === 'Detectado' ? 'bg-success' : 'bg-warning'; ?>"><?php echo $results['cache_headers']; ?></span></div>
                    <div style="padding: 12px 0; border-bottom: 1px solid var(--border);"><div style="display: flex; justify-content: space-between;"><span class="metric-label">JavaScript</span><?php $jsStats = $results['js_stats']; $unminifiedCount = count($jsStats['list_unminified']); ?><span class="badge <?php echo $unminifiedCount === 0 ? 'bg-success' : 'bg-danger'; ?>"><?php echo $unminifiedCount; ?>/<?php echo $jsStats['total']; ?> Sin Minificar</span></div><?php if (!empty($jsStats['list_unminified'])): ?><details><summary style="font-size:0.9em; color:var(--danger);">Ver archivos sin minificar</summary><ul class="file-list"><?php foreach($jsStats['list_unminified'] as $file): ?><li><a href="<?php echo htmlspecialchars($file); ?>" target="_blank"><?php echo htmlspecialchars($file); ?></a></li><?php endforeach; ?></ul></details><?php endif; ?></div>
                    <div style="padding: 12px 0;"><div style="display: flex; justify-content: space-between;"><span class="metric-label">CSS</span><?php $cssStats = $results['css_stats']; $unminifiedCount = count($cssStats['list_unminified']); ?><span class="badge <?php echo $unminifiedCount === 0 ? 'bg-success' : 'bg-danger'; ?>"><?php echo $unminifiedCount; ?>/<?php echo $cssStats['total']; ?> Sin Minificar</span></div><?php if (!empty($cssStats['list_unminified'])): ?><details><summary style="font-size:0.9em; color:var(--danger);">Ver archivos sin minificar</summary><ul class="file-list"><?php foreach($cssStats['list_unminified'] as $file): ?><li><a href="<?php echo htmlspecialchars($file); ?>" target="_blank"><?php echo htmlspecialchars($file); ?></a></li><?php endforeach; ?></ul></details><?php endif; ?></div>
                </div>
            </div>

            <!-- TAB 2: GOOGLE CORE WEB VITALS (JS) -->
            <div id="tab-psi" class="tab-content">
                <div class="result-card" style="text-align:center;">
                    <h3>Analizar Core Web Vitals (Móvil)</h3>
                    <p style="color:var(--text-secondary); margin-bottom:20px;">Consulta la API oficial de Google PageSpeed Insights en tiempo real.</p>
                    
                    <button id="btn-run-psi" onclick="runPageSpeed()">▶ Ejecutar Análisis de Google</button>
                    
                    <div id="psi-loading" style="display:none; margin-top:20px;">
                        <p>Analizando en Google Cloud... (15-30s)</p>
                    </div>

                    <div id="psi-results" style="display:none; margin-top:30px; animation: fadeIn 0.5s ease;">
                        <!-- Score PSI -->
                        <div class="score-container">
                            <div class="score-circle-large" id="psi-score-circle" style="background-color: #ccc;">0</div>
                        </div>
                        <h4 style="margin:10px 0 20px;">PageSpeed Score</h4>

                        <!-- Métricas Grid -->
                        <div class="psi-grid">
                            <div class="psi-metric">
                                <div class="psi-label">LCP (Largest Contentful Paint)</div>
                                <div class="psi-value" id="psi-lcp">-</div>
                            </div>
                            <div class="psi-metric">
                                <div class="psi-label">FCP (First Contentful Paint)</div>
                                <div class="psi-value" id="psi-fcp">-</div>
                            </div>
                            <div class="psi-metric">
                                <div class="psi-label">CLS (Layout Shift)</div>
                                <div class="psi-value" id="psi-cls">-</div>
                            </div>
                            <div class="psi-metric">
                                <div class="psi-label">TBT (Total Blocking Time)</div>
                                <div class="psi-value" id="psi-tbt">-</div>
                            </div>
                        </div>
                        <p style="margin-top:20px; font-size:0.8em; color:var(--text-secondary);">Datos proporcionados por Google API v5</p>
                    </div>
                </div>
            </div>

            <!-- TAB 3: HERRAMIENTAS EXTERNAS -->
            <div id="tab-external" class="tab-content">
                <div class="result-card">
                    <h3 style="text-align:center;">Kit de Herramientas Externas</h3>
                    <div class="external-tools">
                        <?php 
                            $cleanUrl = parse_url($results['url'], PHP_URL_HOST); 
                            $fullUrlEnc = urlencode($results['url']);
                        ?>
                        <a href="https://gtmetrix.com/" target="_blank" class="tool-card">
                            <div class="tool-name">GTmetrix</div>
                            <div class="tool-desc">Análisis profundo de cascada y servidor.</div>
                        </a>
                        <a href="https://website.grader.com/es/tests/<?php echo $cleanUrl; ?>" target="_blank" class="tool-card">
                            <div class="tool-name">Website Grader</div>
                            <div class="tool-desc">Evaluación de marketing y rendimiento.</div>
                        </a>
                        <a href="https://www.webpagetest.org/?url=<?php echo $fullUrlEnc; ?>" target="_blank" class="tool-card">
                            <div class="tool-name">WebPageTest</div>
                            <div class="tool-desc">El estándar de oro en métricas de velocidad (Catchpoint).</div>
                        </a>
                        <a href="https://pagespeed.web.dev/analysis?url=<?php echo $fullUrlEnc; ?>" target="_blank" class="tool-card">
                            <div class="tool-name">PageSpeed Web</div>
                            <div class="tool-desc">Versión web completa de Google PSI.</div>
                        </a>
                        <a href="https://securityheaders.com/?q=<?php echo $fullUrlEnc; ?>" target="_blank" class="tool-card">
                            <div class="tool-name">Security Headers</div>
                            <div class="tool-desc">Auditoría de seguridad HTTP.</div>
                        </a>
                        <a href="https://who.is/dns/<?php echo $cleanUrl; ?>" target="_blank" class="tool-card">
                            <div class="tool-name">DNS Check</div>
                            <div class="tool-desc">Revisión de propagación de DNS.</div>
                        </a>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    <?php endif; ?>

    <!-- Historial de Análisis - Solo visible si hay resultados (no en estado inicial) -->
    <?php if (!empty($history) && $results): ?>
        <div class="result-card">
            <div class="section-title" style="margin-top:0; border:none;">Historial Reciente</div>
            <details <?php echo $results ? '' : 'open'; ?>>
                <summary>Ver registro de actividad</summary>
                <table class="history-table">
                    <tbody>
                        <?php foreach($history as $entry): ?>
                            <tr>
                                <td><?php echo date('d/m H:i', $entry['timestamp']); ?></td>
                                <td><?php echo parse_url($entry['url'], PHP_URL_HOST); ?></td>
                                <td style="text-align:right;">
                                    <?php if(isset($entry['score'])): ?>
                                        <span style="font-weight:bold; margin-right:5px; color:var(--text-secondary);">[<?php echo $entry['score']; ?>]</span>
                                    <?php endif; ?>
                                    <?php $hTimeColor = 'var(--danger)'; if ($entry['load_time'] <= 200) $hTimeColor = 'var(--success)'; elseif ($entry['load_time'] <= 1000) $hTimeColor = 'var(--warning)'; ?>
                                    <span style="font-weight:bold; color: <?php echo $hTimeColor; ?>"><?php echo $entry['load_time']; ?> ms</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
    <?php endif; ?>

</div>

<footer>
    <img src="jox2.png" id="footer-logo" alt="JoxDesign Logo" style="height: 40px; margin-bottom: 10px;">
</footer>

<script>
    // --- LÓGICA DE TABS ---
    function openTab(tabName) {
        // 1. Ocultar todos los contenidos
        const contents = document.querySelectorAll('.tab-content');
        contents.forEach(div => div.classList.remove('active'));
        
        // 2. Desactivar todos los botones
        const buttons = document.querySelectorAll('.tab-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        // 3. Activar el seleccionado
        document.getElementById('tab-' + tabName).classList.add('active');
        // Buscar el botón clickeado (truco simple: buscar por el texto onclick o el índice)
        // Para simplificar, asumimos que el orden en el DOM es el mismo
        // Mejor enfoque: asignar ID a los botones o pasar 'this'
        const activeBtn = Array.from(buttons).find(btn => btn.getAttribute('onclick').includes(tabName));
        if(activeBtn) activeBtn.classList.add('active');
    }

    // --- LÓGICA GOOGLE PSI (JS ASÍNCRONO) ---
    // NOTA: Recuerda vincular una Cuenta de Facturación en Google Cloud para evitar errores de cuota.
    const googleApiKey = 'TU_API_KEY_AQUI'; // <--- API Key aqui

    async function runPageSpeed() {
        const url = "<?php echo $targetUrl; ?>"; // URL analizada por PHP
        if(!url) return;

        const btn = document.getElementById('btn-run-psi');
        const loader = document.getElementById('psi-loading');
        const results = document.getElementById('psi-results');

        // UI Reset
        btn.disabled = true;
        btn.style.opacity = "0.7";
        btn.innerHTML = '<span class="loader">↻</span> Analizando...'; // Spinner
        loader.style.display = "block";
        results.style.display = "none";

        try {
            // Llamada API Google (Strategy Mobile)
            let apiUrl = `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=${encodeURIComponent(url)}&strategy=mobile`;
            if (googleApiKey && googleApiKey !== 'TU_API_KEY_AQUI') {
                apiUrl += `&key=${googleApiKey}`;
            }
            
            const response = await fetch(apiUrl);
            const data = await response.json();

            if (data.error) {
                let msg = data.error.message;
                if (data.error.code === 429 || msg.includes('Quota')) {
                    msg = "Error de Cuota: Verifica que tu proyecto en Google Cloud tenga una cuenta de facturación vinculada (requerido incluso para el plan gratuito) o que no hayas excedido el límite diario.";
                }
                alert("Error de Google API: " + msg);
                startCooldown(btn); // Apply cooldown even on error
                return;
            }

            // Procesar Datos
            const lighthouse = data.lighthouseResult;
            const score = Math.round(lighthouse.categories.performance.score * 100);
            const audits = lighthouse.audits;

            // Renderizar Score
            const circle = document.getElementById('psi-score-circle');
            circle.innerText = score;
            
            let color = '#d63031'; // Red
            if(score >= 90) color = '#00b894'; // Green
            else if(score >= 50) color = '#fdcb6e'; // Orange
            
            circle.style.backgroundColor = color;
            if(score === 100) circle.classList.add('pulse-100'); else circle.classList.remove('pulse-100');

            // Renderizar Métricas
            renderMetric('lcp', audits['largest-contentful-paint']);
            renderMetric('fcp', audits['first-contentful-paint']);
            renderMetric('cls', audits['cumulative-layout-shift']);
            renderMetric('tbt', audits['total-blocking-time']);

            loader.style.display = "none";
            results.style.display = "block";
            
            startCooldown(btn);

        } catch (error) {
            console.error(error);
            alert("Error de conexión al analizar PSI.");
            startCooldown(btn);
        }
    }

    function startCooldown(btn) {
        let seconds = 60;
        btn.disabled = true;
        btn.style.opacity = "0.5";
        
        // Initial text set before interval starts
        btn.innerText = `⏳ Esperar ${seconds}s...`;

        const timer = setInterval(() => {
            seconds--;
            btn.innerText = `⏳ Esperar ${seconds}s...`;

            if (seconds <= 0) {
                clearInterval(timer);
                btn.disabled = false;
                btn.style.opacity = "1";
                btn.innerText = "🔄 Ejecutar de nuevo";
            }
        }, 1000);
    }

    function renderMetric(id, audit) {
        const el = document.getElementById('psi-' + id);
        if(audit) {
            el.innerText = audit.displayValue;
            // Color coding básico de Google
            if(audit.score >= 0.9) el.style.color = 'var(--success)';
            else if(audit.score >= 0.5) el.style.color = 'var(--warning)';
            else el.style.color = 'var(--danger)';
        }
    }

    // --- LÓGICA MODO OSCURO ---
    const toggleButton = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const html = document.documentElement;

    const savedTheme = localStorage.getItem('seo_theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    updateIcon(savedTheme);

    toggleButton.addEventListener('click', () => {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('seo_theme', newTheme);
        updateIcon(newTheme);
    });

    function updateIcon(theme) {
        const footerLogo = document.getElementById('footer-logo');
        if (theme === 'dark') {
            themeIcon.textContent = '🌙';
            toggleButton.title = "Cambiar a modo claro";
            if (footerLogo) footerLogo.src = 'jox2.png';
        } else {
            themeIcon.textContent = '☀️';
            toggleButton.title = "Cambiar a modo oscuro";
            if (footerLogo) footerLogo.src = 'jox3.png';
        }
    }
</script>

</body>
</html>