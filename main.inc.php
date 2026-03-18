<?php
/*
Plugin Name: Claude AI Tagger
Version: 2.17.0
Description: Uses Claude AI vision to automatically generate and apply relevant tags to your Piwigo photos. Detects objects, scenes, faces, logos, colors, and more.
Plugin URI: https://piwigo.org/ext/extension_view.php?eid=claude_tagger
Author: Claude AI Tagger
Has Settings: true
Author URI: https://www.anthropic.com
*/

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('CLAUDE_TAGGER_DIR',   basename(dirname(__FILE__)));
define('CLAUDE_TAGGER_PATH',  PHPWG_PLUGINS_PATH . CLAUDE_TAGGER_DIR . '/');
define('CLAUDE_TAGGER_ADMIN', get_root_url() . 'admin.php?page=plugin-' . CLAUDE_TAGGER_DIR);

load_language('plugin.lang', CLAUDE_TAGGER_PATH . 'languages/');

add_event_handler('loc_begin_admin_page', 'claude_tagger_admin_menu');
add_event_handler('init',                 'claude_tagger_init');
add_event_handler('add_uploaded_file',    'claude_tagger_on_new_photo');

function claude_tagger_init()
{
    global $conf;
    if (empty($conf['claude_tagger'])) {
        $conf['claude_tagger'] = claude_tagger_default_config();
    }
}

function claude_tagger_default_config(): array
{
    return [
        'api_key'            => '',
        'model'              => 'claude-haiku-4-5-20251001',
        'auto_tag_on_upload' => false,
        'max_tags'           => 20,
        'min_confidence'     => 'medium',
        'tag_language'       => 'en',
        'tag_categories'     => [
            'objects'  => true,
            'scenes'   => true,
            'people'   => true,
            'actions'  => true,
            'colors'   => false,
            'mood'     => false,
            'text_ocr' => false,
            'logos'    => true,
            'animals'  => true,
            'food'     => true,
            'vehicles' => true,
            'nature'   => true,
        ],
        'custom_prompt'   => '',
        'tag_prefix'      => '',
        'overwrite_tags'  => false,
        'create_new_tags' => true,
    ];
}

function claude_tagger_admin_menu()
{
    global $page;
    if (isset($page['page']) && $page['page'] === 'plugin-' . CLAUDE_TAGGER_DIR) {
        include CLAUDE_TAGGER_PATH . 'admin.php';
    }
}

function claude_tagger_on_new_photo($image_id)
{
    global $conf;
    $cfg = is_array($conf['claude_tagger'] ?? null)
         ? $conf['claude_tagger']
         : claude_tagger_default_config();
    if (!empty($cfg['auto_tag_on_upload']) && !empty($cfg['api_key'])) {
        $id = is_array($image_id) ? ($image_id['image_id'] ?? null) : $image_id;
        if ($id) claude_tagger_tag_image((int)$id);
    }
}

function claude_tagger_decrypt_key(string $enc): string
{
    if ($enc === '') return '';
    if (strpos($enc, 'sk-ant') === 0) return $enc; // plain text legacy
    global $conf;
    $secret = hash('sha256', ($conf['secret_key'] ?? 'piwigo_claude_tagger'), true);
    $raw    = base64_decode($enc);
    if (strlen($raw) < 17) return '';
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $secret, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : '';
}

/**
 * Ensure the image is within the Anthropic API limit of 5 MB (5,242,880 bytes).
 * If the file exceeds the limit, resample it down with GD/Imagick and write a
 * temp file. Returns an array with:
 *   'path'     => path to use (original or temp)
 *   'mime'     => final MIME type
 *   'is_temp'  => bool — caller must unlink() when done
 */
function claude_tagger_prepare_image(string $file_path, string $mime): array
{
    // The API limit is 5 MB of base64-encoded data.
    // Base64 inflates size by 4/3, so the raw file must be under 5242880 * 3/4 = 3932160 bytes.
    $api_max_b64  = 5242880;          // 5 MB base64 limit
    $raw_limit    = 3932160;          // = 5242880 * 3/4  (raw bytes that fit after encoding)

    clearstatcache(true, $file_path);
    $size = filesize($file_path);

    if ($size <= $raw_limit) {
        return ['path' => $file_path, 'mime' => $mime, 'is_temp' => false];
    }

    // Generate a proper temp path (don't append to tempnam result — it creates the file)
    $tmp = tempnam(sys_get_temp_dir(), 'ct_img_');
    if ($tmp === false) {
        return ['path' => $file_path, 'mime' => $mime, 'is_temp' => false,
                'error' => 'Could not create temp file for image resize.'];
    }
    // Replace with a .jpg path; remove the placeholder tempnam created
    @unlink($tmp);
    $tmp .= '.jpg';

    // ── Try GD ────────────────────────────────────────────────────────────────
    if (extension_loaded('gd')) {
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file_path),
            'image/png'  => @imagecreatefrompng($file_path),
            'image/gif'  => @imagecreatefromgif($file_path),
            'image/webp' => @imagecreatefromwebp($file_path),
            default      => false,
        };

        if ($src !== false) {
            $orig_w  = imagesx($src);
            $orig_h  = imagesy($src);

            // Scale so that raw output bytes fit within $raw_limit (with 10% margin)
            $scale  = sqrt($raw_limit / $size) * 0.85;
            $scale  = min($scale, 1.0);
            $new_w  = max(1, (int)round($orig_w * $scale));
            $new_h  = max(1, (int)round($orig_h * $scale));

            $dst = imagecreatetruecolor($new_w, $new_h);
            if ($mime === 'image/png') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            imagedestroy($src);

            // Write at quality 85, then reduce if still too large
            $quality = 85;
            imagejpeg($dst, $tmp, $quality);
            clearstatcache(true, $tmp);

            while (filesize($tmp) > $raw_limit && $quality > 20) {
                $quality -= 10;
                imagejpeg($dst, $tmp, $quality);
                clearstatcache(true, $tmp);
            }

            imagedestroy($dst);
            return ['path' => $tmp, 'mime' => 'image/jpeg', 'is_temp' => true];
        }
    }

    // ── Fallback: Imagick ─────────────────────────────────────────────────────
    if (extension_loaded('imagick')) {
        try {
            $img   = new Imagick($file_path);
            $scale = sqrt($raw_limit / $size) * 0.85;
            $scale = min($scale, 1.0);
            $img->resizeImage(
                (int)round($img->getImageWidth()  * $scale),
                (int)round($img->getImageHeight() * $scale),
                Imagick::FILTER_LANCZOS, 1
            );
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(85);
            $img->writeImage($tmp);
            $img->destroy();
            return ['path' => $tmp, 'mime' => 'image/jpeg', 'is_temp' => true];
        } catch (Exception $e) {
            // fall through
        }
    }

    @unlink($tmp); // clean up unused temp file
    return ['path' => $file_path, 'mime' => $mime, 'is_temp' => false,
            'error' => 'Image is ' . round($size / 1048576, 1) . ' MB and exceeds the 5 MB API limit. No image library (GD/Imagick) is available to resize it.'];
}

function claude_tagger_tag_image(int $image_id): array
{
    global $conf;
    $cfg = is_array($conf['claude_tagger'] ?? null)
         ? $conf['claude_tagger']
         : claude_tagger_default_config();

    $cfg['api_key'] = claude_tagger_decrypt_key($cfg['api_key'] ?? '');
    if (empty($cfg['api_key'])) {
        return ['success' => false, 'tags' => [], 'message' => 'API key not configured.'];
    }

    $query  = 'SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ' . $image_id . ' LIMIT 1;';
    $result = pwg_query($query);
    $image  = pwg_db_fetch_assoc($result);
    if (!$image) {
        return ['success' => false, 'tags' => [], 'message' => "Image $image_id not found."];
    }

    $file_path = PHPWG_ROOT_PATH . $image['path'];
    if (!file_exists($file_path)) {
        $file_path = PHPWG_ROOT_PATH . 'upload/' . $image['path'];
    }
    if (!file_exists($file_path)) {
        return ['success' => false, 'tags' => [], 'message' => "File not found: {$image['path']}"];
    }

    $mime      = mime_content_type($file_path) ?: 'image/jpeg';
    $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $supported, true)) {
        return ['success' => false, 'tags' => [], 'message' => "Unsupported MIME type: $mime"];
    }

    // Resize to ≤ 5 MB if needed (Anthropic API limit)
    $img_info = claude_tagger_prepare_image($file_path, $mime);
    if (!empty($img_info['error'])) {
        return ['success' => false, 'tags' => [], 'message' => $img_info['error']];
    }

    $image_data  = base64_encode(file_get_contents($img_info['path']));
    $mime        = $img_info['mime'];
    $tags_result = claude_tagger_call_api($image_data, $mime, $cfg, $image);

    // Clean up temp file if one was created
    if ($img_info['is_temp'] && file_exists($img_info['path'])) {
        @unlink($img_info['path']);
    }
    if (!$tags_result['success']) return $tags_result;

    $raw_tags = $tags_result['tags'];
    $prefix   = trim($cfg['tag_prefix'] ?? '');
    if ($prefix !== '') {
        $raw_tags = array_map(fn($t) => $prefix . $t, $raw_tags);
    }

    $applied = claude_tagger_apply_tags($image_id, $raw_tags, $cfg);
    return [
        'success' => true,
        'tags'    => $applied,
        'message' => sprintf('Tagged image #%d with %d tags.', $image_id, count($applied)),
    ];
}

function claude_tagger_call_api(string $image_data, string $mime, array $cfg, array $image_meta): array
{
    $payload = [
        'model'      => $cfg['model'] ?? 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $image_data]],
                ['type' => 'text',  'text'   => claude_tagger_build_prompt($cfg, $image_meta)],
            ],
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['success' => false, 'tags' => [], 'message' => "cURL error: $curl_err"];
    $body = json_decode($response, true);
    if ($http_code !== 200) {
        $err = $body['error']['message'] ?? $response;
        return ['success' => false, 'tags' => [], 'message' => "API error $http_code: $err"];
    }

    $text = '';
    foreach ($body['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }
    $tags = claude_tagger_parse_tags($text, (int)($cfg['max_tags'] ?? 20));
    return ['success' => true, 'tags' => $tags, 'message' => ''];
}

function claude_tagger_build_prompt(array $cfg, array $image_meta): string
{
    $cats    = $cfg['tag_categories'] ?? [];
    $enabled = array_keys(array_filter($cats));
    $lang    = $cfg['tag_language'] ?? 'en';
    $max     = (int)($cfg['max_tags'] ?? 20);
    $conf_lvl = $cfg['min_confidence'] ?? 'medium';
    $custom  = trim($cfg['custom_prompt'] ?? '');

    $cat_label_map = [
        'objects'  => 'physical objects and items',
        'scenes'   => 'scenes, settings, and environments',
        'people'   => 'people, faces, expressions, demographics',
        'actions'  => 'actions and activities',
        'colors'   => 'dominant colors and color palette',
        'mood'     => 'mood, atmosphere, and emotions',
        'text_ocr' => 'visible text, signs, and labels',
        'logos'    => 'logos, brands, and symbols',
        'animals'  => 'animals and wildlife',
        'food'     => 'food and beverages',
        'vehicles' => 'vehicles and transportation',
        'nature'   => 'nature, plants, and landscapes',
    ];

    $cat_list = implode(', ', array_map(fn($k) => $cat_label_map[$k] ?? $k, $enabled));
    $conf_instruction = match($conf_lvl) {
        'high'  => 'Only include tags you are very confident about.',
        'low'   => 'Include tags even if you are only slightly confident.',
        default => 'Include tags you are reasonably confident about.',
    };
    $lang_instruction = ($lang !== 'en') ? "Respond with tags in language code \"$lang\"." : 'Respond with tags in English.';

    $prompt = "Analyze this image and generate descriptive tags for a photo library.\n\n"
            . "Focus on these categories: {$cat_list}.\n\n"
            . "Rules:\n"
            . "- Return ONLY a valid JSON array of lowercase tag strings, no other text.\n"
            . "- Maximum {$max} tags.\n"
            . "- Each tag should be 1-4 words, specific, and relevant.\n"
            . "- Use hyphens for multi-word tags (e.g. \"golden-retriever\").\n"
            . "- {$conf_instruction}\n"
            . "- {$lang_instruction}\n"
            . "- No duplicate tags.\n";

    if ($custom !== '') $prompt .= "\nAdditional instructions: $custom\n";
    $prompt .= "\nReturn only the JSON array. Example: [\"dog\",\"park\",\"sunny-day\"]";
    return $prompt;
}

function claude_tagger_parse_tags(string $text, int $max): array
{
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/```/', '', $text);
    $text = trim($text);
    if (preg_match('/\[.*\]/s', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            $tags = array_filter(array_map('trim', $decoded), fn($t) => is_string($t) && $t !== '');
            $tags = array_map(fn($t) => preg_replace('/[^a-z0-9\-_ ]/i', '', strtolower($t)), array_values($tags));
            $tags = array_filter($tags, fn($t) => strlen($t) >= 2 && strlen($t) <= 80);
            return array_slice(array_unique(array_values($tags)), 0, $max);
        }
    }
    return [];
}

function claude_tagger_apply_tags(int $image_id, array $tag_names, array $cfg): array
{
    if (empty($tag_names)) return [];
    $create_new = !empty($cfg['create_new_tags']);
    $overwrite  = !empty($cfg['overwrite_tags']);

    if ($overwrite) {
        pwg_query('DELETE FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id = ' . $image_id . ';');
    }

    $applied = [];
    foreach ($tag_names as $name) {
        $name_esc = pwg_db_real_escape_string($name);
        $res   = pwg_query("SELECT id FROM " . TAGS_TABLE . " WHERE LOWER(name) = LOWER('$name_esc') LIMIT 1;");
        $row   = pwg_db_fetch_assoc($res);
        $tag_id = $row ? (int)$row['id'] : null;

        if (!$tag_id && $create_new) {
            $url_name = str2url($name);
            pwg_query("INSERT INTO " . TAGS_TABLE . " (name, url_name) VALUES ('$name_esc', '$url_name');");
            $tag_id = pwg_db_insert_id(TAGS_TABLE);
        }

        if (!$tag_id) continue;
        pwg_query("INSERT IGNORE INTO " . IMAGE_TAG_TABLE . " (image_id, tag_id) VALUES ($image_id, $tag_id);");
        $applied[] = $name;
    }

    if (!empty($applied)) {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        update_global_rank();
    }
    return $applied;
}

function claude_tagger_batch_tag(?array $image_ids = null, int $limit = 50): array
{
    global $conf;
    $cfg = is_array($conf['claude_tagger'] ?? null)
         ? $conf['claude_tagger']
         : claude_tagger_default_config();

    if (empty($cfg['api_key'])) {
        return ['success' => false, 'processed' => 0, 'errors' => [], 'message' => 'API key not configured.'];
    }

    if ($image_ids === null) {
        $q   = 'SELECT id FROM ' . IMAGES_TABLE . ' ORDER BY date_available DESC LIMIT ' . $limit . ';';
        $res = pwg_query($q);
        $image_ids = [];
        while ($row = pwg_db_fetch_assoc($res)) $image_ids[] = (int)$row['id'];
    }

    $processed = 0;
    $errors    = [];
    foreach ($image_ids as $id) {
        $result = claude_tagger_tag_image((int)$id);
        if ($result['success']) $processed++;
        else $errors[$id] = $result['message'];
        usleep(300000);
    }

    return ['success' => true, 'processed' => $processed, 'errors' => $errors, 'message' => "Processed $processed images."];
}
