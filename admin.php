<?php
/**
 * Claude AI Tagger – Admin page (Piwigo 16.x)
 */
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');
if (!is_admin()) access_denied();

global $template, $conf;

// ── Encryption helpers ────────────────────────────────────────────────────────
function ct_encrypt_key($key)
{
    if ($key === '') return '';
    global $conf;
    $secret = hash('sha256', isset($conf['secret_key']) ? $conf['secret_key'] : 'piwigo_claude_tagger', true);
    $iv  = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($key, 'AES-256-CBC', $secret, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function ct_decrypt_key($enc)
{
    if ($enc === '') return '';
    if (strpos($enc, 'sk-ant') === 0) return $enc; // plain-text legacy
    global $conf;
    $secret = hash('sha256', isset($conf['secret_key']) ? $conf['secret_key'] : 'piwigo_claude_tagger', true);
    $raw = base64_decode($enc);
    if (strlen($raw) < 17) return '';
    $iv      = substr($raw, 0, 16);
    $payload = substr($raw, 16);
    $dec = openssl_decrypt($payload, 'AES-256-CBC', $secret, OPENSSL_RAW_DATA, $iv);
    return ($dec !== false) ? $dec : '';
}

// ── Config helpers ────────────────────────────────────────────────────────────
function ct_save_config($new)
{
    global $conf;
    $conf['claude_tagger'] = $new;
    $serialized = pwg_db_real_escape_string(serialize($new));
    $exists = pwg_db_fetch_assoc(pwg_query(
        "SELECT param FROM " . CONFIG_TABLE . " WHERE param = 'claude_tagger' LIMIT 1;"
    ));
    if ($exists) {
        pwg_query("UPDATE " . CONFIG_TABLE . " SET value = '$serialized' WHERE param = 'claude_tagger';");
    } else {
        pwg_query("INSERT INTO " . CONFIG_TABLE . " (param, value) VALUES ('claude_tagger', '$serialized');");
    }
}

function ct_load_config()
{
    global $conf;

    $model_remap = array(
        'claude-opus-4-6'   => 'claude-opus-4-5-20251101',
        'claude-sonnet-4-6' => 'claude-sonnet-4-5-20250929',
        'claude-opus-4-5'   => 'claude-opus-4-5-20251101',
        'claude-sonnet-4-5' => 'claude-sonnet-4-5-20250929',
    );

    $row = pwg_db_fetch_assoc(pwg_query(
        "SELECT value FROM " . CONFIG_TABLE . " WHERE param = 'claude_tagger' LIMIT 1;"
    ));

    if ($row) {
        $raw = $row['value'];
        $v   = null;

        if (is_array($raw)) {
            $v = $raw;
        } else {
            $d1 = @unserialize($raw);
            if (is_array($d1)) {
                $v = $d1;
            } elseif (is_string($d1)) {
                $d2 = @unserialize($d1);
                if (is_array($d2)) {
                    $v = $d2; // double-serialized recovery
                }
            }
        }

        if (is_array($v)) {
            if (!empty($v['model']) && isset($model_remap[$v['model']])) {
                $v['model'] = $model_remap[$v['model']];
            }
            // Rewrite if it was double-serialized
            $d1 = @unserialize($raw);
            if (is_string($d1)) {
                ct_save_config($v);
            } else {
                $conf['claude_tagger'] = $v;
            }
            return $v;
        }
    }

    $def = claude_tagger_default_config();
    ct_save_config($def);
    return $def;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$cfg           = ct_load_config();
$page_message  = '';
$page_msg_type = 'ok';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    check_pwg_token();

    if ($_POST['action'] === 'save_settings') {
        $allowed_models = array(
            'claude-haiku-4-5-20251001',
            'claude-sonnet-4-5-20250929',
            'claude-opus-4-5-20251101',
        );

        // Preserve existing key if field left blank
        $submitted_key = trim(isset($_POST['api_key']) ? $_POST['api_key'] : '');
        if ($submitted_key !== '') {
            $api_key_to_save = ct_encrypt_key($submitted_key);
        } else {
            $api_key_to_save = isset($cfg['api_key']) ? $cfg['api_key'] : '';
        }

        $new = array(
            'api_key'            => $api_key_to_save,
            'model'              => in_array(isset($_POST['model']) ? $_POST['model'] : '', $allowed_models, true)
                                        ? $_POST['model'] : 'claude-haiku-4-5-20251001',
            'auto_tag_on_upload' => !empty($_POST['auto_tag_on_upload']),
            'max_tags'           => max(1, min(50, (int)(isset($_POST['max_tags']) ? $_POST['max_tags'] : 20))),
            'min_confidence'     => in_array(isset($_POST['min_confidence']) ? $_POST['min_confidence'] : '', array('low','medium','high'), true)
                                        ? $_POST['min_confidence'] : 'medium',
            'tag_language'       => preg_replace('/[^a-zA-Z\-]/', '', isset($_POST['tag_language']) ? $_POST['tag_language'] : 'en'),
            'tag_prefix'         => substr(preg_replace('/[^a-z0-9_\-:]/i', '', isset($_POST['tag_prefix']) ? $_POST['tag_prefix'] : ''), 0, 20),
            'overwrite_tags'     => !empty($_POST['overwrite_tags']),
            'create_new_tags'    => !empty($_POST['create_new_tags']),
            'custom_prompt'      => substr(strip_tags(isset($_POST['custom_prompt']) ? $_POST['custom_prompt'] : ''), 0, 500),
            'tag_categories'     => array(),
        );

        foreach (array_keys(claude_tagger_default_config()['tag_categories']) as $cat) {
            $new['tag_categories'][$cat] = !empty($_POST['cat_' . $cat]);
        }

        ct_save_config($new);
        $cfg           = $new;
        $page_message  = l10n('claude_tagger_settings_saved');
        $page_msg_type = 'ok';
    }

    if ($_POST['action'] === 'test_api') {
        $test_key = trim(isset($_POST['api_key']) ? $_POST['api_key'] : ct_decrypt_key(isset($cfg['api_key']) ? $cfg['api_key'] : ''));
        if (empty($test_key)) {
            $page_message  = l10n('claude_tagger_no_api_key');
            $page_msg_type = 'errors';
        } else {
            $payload = json_encode(array(
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 10,
                'messages'   => array(array('role' => 'user', 'content' => 'Say OK')),
            ));
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    'x-api-key: ' . $test_key,
                    'anthropic-version: 2023-06-01',
                ),
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ));
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($cerr) {
                $page_message  = 'cURL error: ' . htmlspecialchars($cerr);
                $page_msg_type = 'errors';
            } elseif ($code === 200) {
                $page_message  = l10n('claude_tagger_api_ok');
                $page_msg_type = 'ok';
            } else {
                $body     = json_decode($resp, true);
                $err_type = htmlspecialchars(isset($body['error']['type'])    ? $body['error']['type']    : '');
                $err_msg  = htmlspecialchars(isset($body['error']['message']) ? $body['error']['message'] : '');
                $page_message = "HTTP $code $err_type: $err_msg"
                    . '<br><small style="font-family:monospace;word-break:break-all">'
                    . htmlspecialchars(substr($resp, 0, 500)) . '</small>';

                $raw_msg = strtolower(isset($body['error']['message']) ? $body['error']['message'] : '');
                if (strpos($raw_msg, 'credit') !== false || strpos($raw_msg, 'billing') !== false || strpos($raw_msg, 'balance') !== false) {
                    $page_message .= '<br><br><a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener" class="ct-billing-link">'
                        . '💳 Go to Anthropic Console → Plans &amp; Billing to purchase credits</a>';
                }
                $page_msg_type = 'errors';
            }
        }
    }

    if ($_POST['action'] === 'tag_single') {
        $image_id = (int)(isset($_POST['image_id']) ? $_POST['image_id'] : 0);
        if ($image_id > 0) {
            $result        = claude_tagger_tag_image($image_id);
            $page_message  = $result['message'];
            $page_msg_type = $result['success'] ? 'ok' : 'errors';
        } else {
            $page_message  = l10n('claude_tagger_invalid_image_id');
            $page_msg_type = 'errors';
        }
    }

    if ($_POST['action'] === 'tag_single_debug') {
        $image_id = (int)(isset($_POST['image_id_debug']) ? $_POST['image_id_debug'] : 0);
        if ($image_id > 0) {
            // Look up the image
            $qimg   = 'SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ' . $image_id . ' LIMIT 1;';
            $rimg   = pwg_query($qimg);
            $imgrow = pwg_db_fetch_assoc($rimg);
            if ($imgrow) {
                $fp = PHPWG_ROOT_PATH . $imgrow['path'];
                if (!file_exists($fp)) $fp = PHPWG_ROOT_PATH . 'upload/' . $imgrow['path'];
                if (file_exists($fp)) {
                    $orig_size = filesize($fp);
                    $orig_name = basename($fp);
                    $mime      = mime_content_type($fp) ?: 'image/jpeg';
                    $prepared  = claude_tagger_prepare_image($fp, $mime);
                    if ($prepared['is_temp']) {
                        $temp_name = basename($prepared['path']);
                        $temp_size = filesize($prepared['path']);
                        @unlink($prepared['path']);
                        $page_message = "Original: <strong>$orig_name</strong> — "
                            . number_format($orig_size) . ' bytes ('
                            . round($orig_size / 1048576, 2) . ' MB)<br>'
                            . "Resized temp: <strong>$temp_name</strong> — "
                            . number_format($temp_size) . ' bytes ('
                            . round($temp_size / 1048576, 2) . ' MB)';
                    } else {
                        $page_message = "Original: <strong>$orig_name</strong> — "
                            . number_format($orig_size) . ' bytes ('
                            . round($orig_size / 1048576, 2) . ' MB)<br>'
                            . "No resize needed (under 5 MB limit).";
                    }
                    $page_msg_type = 'info';
                } else {
                    $page_message  = 'File not found on disk.';
                    $page_msg_type = 'errors';
                }
            } else {
                $page_message  = 'Photo ID not found.';
                $page_msg_type = 'errors';
            }
        } else {
            $page_message  = 'Enter a valid photo ID.';
            $page_msg_type = 'errors';
        }
    }

    if ($_POST['action'] === 'tag_batch') {
        $ids_raw = trim(isset($_POST['image_ids']) ? $_POST['image_ids'] : '');
        $limit   = max(1, min(200, (int)(isset($_POST['batch_limit']) ? $_POST['batch_limit'] : 20)));
        if ($ids_raw !== '') {
            $ids    = array_values(array_filter(
                array_map('intval', preg_split('/[\s,]+/', $ids_raw, -1, PREG_SPLIT_NO_EMPTY)),
                function($i) { return $i > 0; }
            ));
            $result = claude_tagger_batch_tag($ids ? $ids : null, $limit);
        } else {
            $result = claude_tagger_batch_tag(null, $limit);
        }
        $err_count     = count(isset($result['errors']) ? $result['errors'] : array());
        $page_message  = sprintf(l10n('claude_tagger_batch_done'), $result['processed'], $err_count);
        $page_msg_type = ($err_count === 0) ? 'ok' : 'info';
        foreach (isset($result['errors']) ? $result['errors'] : array() as $img_id => $err_msg) {
            $page_message .= '<br>• Image #' . (int)$img_id . ': ' . htmlspecialchars($err_msg);
        }
    }
}

// ── Recent photos ─────────────────────────────────────────────────────────────
$recent_images = array();
$rq = 'SELECT i.id, i.file, i.name, i.path, i.date_available,
       GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ", ") AS tag_list
       FROM ' . IMAGES_TABLE . ' i
       LEFT JOIN ' . IMAGE_TAG_TABLE . ' it ON i.id = it.image_id
       LEFT JOIN ' . TAGS_TABLE . ' t ON it.tag_id = t.id
       GROUP BY i.id ORDER BY i.date_available DESC LIMIT 10;';
$rr = pwg_query($rq);
while ($row = pwg_db_fetch_assoc($rr)) {
    $row['filename']   = basename(isset($row['file']) ? $row['file'] : (isset($row['path']) ? $row['path'] : ''));
    $row['tags_array'] = !empty($row['tag_list'])
                         ? array_map('trim', explode(', ', $row['tag_list']))
                         : array();
    $recent_images[] = $row;
}

// ── Assign to template ────────────────────────────────────────────────────────
$pwg_token = get_pwg_token();

$template->assign(array(
    'CT_TOKEN'         => $pwg_token,
    'CT_ADMIN_URL'     => CLAUDE_TAGGER_ADMIN,
    'CT_CFG'           => $cfg,
    'CT_KEY_SAVED'     => !empty($cfg['api_key']),
    'CT_MESSAGE'       => $page_message,
    'CT_MSG_TYPE'      => $page_msg_type,
    'CT_RECENT'        => $recent_images,
    'CT_PLUGIN_PATH'   => CLAUDE_TAGGER_PATH,
    'CT_ALL_CATS'      => array(
        'objects'  => 'Objects & Items',
        'scenes'   => 'Scenes & Environments',
        'people'   => 'People & Faces',
        'actions'  => 'Actions & Activities',
        'colors'   => 'Colors & Palette',
        'mood'     => 'Mood & Atmosphere',
        'text_ocr' => 'Text & Signs (OCR)',
        'logos'    => 'Logos & Brands',
        'animals'  => 'Animals & Wildlife',
        'food'     => 'Food & Beverages',
        'vehicles' => 'Vehicles',
        'nature'   => 'Nature & Landscapes',
    ),
    'CT_MODELS'        => array(
        'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 – Fastest & most economical (recommended)',
        'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 – Balanced speed & quality',
        'claude-opus-4-5-20251101'   => 'Claude Opus 4.5 – Most capable',
    ),
));

$template->set_filename('plugin_admin_content', CLAUDE_TAGGER_PATH . 'admin/template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
