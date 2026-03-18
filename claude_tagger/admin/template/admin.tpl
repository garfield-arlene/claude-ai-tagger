{* Claude AI Tagger – Admin Template (Piwigo 16 Smarty 5 compatible) *}
{* Rendered via $template->set_filename() + assign_var_from_handle()  *}
{* This is the fix: using Piwigo's template engine means submit buttons*}
{* are handled correctly by Piwigo's admin JS pipeline.               *}

<link rel="stylesheet" href="{$CT_PLUGIN_PATH}admin/admin.css">

<div id="ct-wrap">

  <div class="ct-header">
    <div class="ct-header-inner">
      <div class="ct-logo">
        <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect width="36" height="36" rx="8" fill="#D4A574"/>
          <circle cx="18" cy="15" r="6" stroke="white" stroke-width="2" fill="none"/>
          <path d="M13 24 Q18 20 23 24" stroke="white" stroke-width="2" fill="none" stroke-linecap="round"/>
        </svg>
      </div>
      <div>
        <h2 class="ct-title">Claude AI Tagger</h2>
        <p class="ct-subtitle">Automatically tag your photos using Claude AI vision — v2.17.0</p>
      </div>
    </div>
  </div>

  {if $CT_MESSAGE != ''}
  <div class="ct-alert ct-alert-{$CT_MSG_TYPE}">{$CT_MESSAGE}</div>
  {/if}

  <div class="ct-tabs">
    <button class="ct-tab ct-tab-active" data-tab="ct-settings" type="button">⚙️ {'Settings'|translate}</button>
    <button class="ct-tab" data-tab="ct-tagging"  type="button">🏷️ {'Tag Photos'|translate}</button>
    <button class="ct-tab" data-tab="ct-gallery"  type="button">🖼️ {'Recent Photos'|translate}</button>
  </div>

  {* ═══════════════════════════════════════════════════════════════════ *}
  {* SETTINGS TAB                                                        *}
  {* ═══════════════════════════════════════════════════════════════════ *}
  <div id="ct-settings" class="ct-panel ct-panel-active">
    <form method="post" action="{$CT_ADMIN_URL}">
      <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
      <input type="hidden" name="action"    value="save_settings">

      <div class="ct-card">
        <h3 class="ct-card-title">🔑 {'API Configuration'|translate}</h3>
        <div class="ct-field">
          <label for="ct_api_key">{'Anthropic API Key'|translate} <span class="ct-req">*</span></label>
          <div class="ct-row">
            {* Don't pre-fill with encrypted ciphertext — show placeholder instead *}
            <input type="password" id="ct_api_key" name="api_key"
                   value=""
                   placeholder="{if $CT_KEY_SAVED}{'API key saved — enter new key to replace'|translate}{else}sk-ant-api03-…{/if}"
                   class="ct-input ct-input-wide" autocomplete="new-password">
            <button type="button" id="ct-show-key" class="ct-btn ct-btn-ghost ct-btn-sm">{'Show'|translate}</button>
          </div>
          <p class="ct-hint"><a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a></p>
        </div>
        <div class="ct-field">
          <label for="ct_model">{'Model'|translate}</label>
          <select id="ct_model" name="model" class="ct-select">
            {foreach $CT_MODELS as $val => $label}
            <option value="{$val}" {if $CT_CFG.model == $val}selected{/if}>{$label|escape:'html'}</option>
            {/foreach}
          </select>
        </div>

        <div class="ct-row" style="margin-top:8px">
          <button type="button" id="ct-test-btn" class="ct-btn ct-btn-secondary">🔌 {'Test Connection'|translate}</button>
          <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener" class="ct-btn ct-btn-ghost ct-btn-sm ct-billing-btn">
            💳 {'Purchase API Credits'|translate}
          </a>
        </div>
      </div>

      <div class="ct-card">
        <h3 class="ct-card-title">🏷️ {'Tag Behaviour'|translate}</h3>
        <div class="ct-grid2">
          <div class="ct-field">
            <label for="ct_max_tags">{'Maximum Tags per Photo'|translate}</label>
            <input type="number" id="ct_max_tags" name="max_tags" min="1" max="50"
                   value="{$CT_CFG.max_tags|intval}" class="ct-input">
          </div>
          <div class="ct-field">
            <label for="ct_confidence">{'Confidence Level'|translate}</label>
            <select id="ct_confidence" name="min_confidence" class="ct-select">
              <option value="low"    {if $CT_CFG.min_confidence == 'low'}selected{/if}>{'Low – Include everything'|translate}</option>
              <option value="medium" {if $CT_CFG.min_confidence == 'medium'}selected{/if}>{'Medium – Balanced'|translate}</option>
              <option value="high"   {if $CT_CFG.min_confidence == 'high'}selected{/if}>{'High – Only certain tags'|translate}</option>
            </select>
          </div>
          <div class="ct-field">
            <label for="ct_lang">{'Tag Language'|translate}</label>
            <input type="text" id="ct_lang" name="tag_language"
                   value="{$CT_CFG.tag_language|escape:'html'}" placeholder="en" class="ct-input" maxlength="10">
            <p class="ct-hint">{'ISO 639-1 code: en, fr, de, es, ja…'|translate}</p>
          </div>
          <div class="ct-field">
            <label for="ct_prefix">{'Tag Prefix'|translate} <em>(optional)</em></label>
            <input type="text" id="ct_prefix" name="tag_prefix"
                   value="{$CT_CFG.tag_prefix|escape:'html'}" placeholder="ai:" class="ct-input" maxlength="20">
            <p class="ct-hint">{'Prepended to every generated tag'|translate}</p>
          </div>
        </div>
        <div class="ct-checks">
          <label class="ct-check">
            <input type="checkbox" name="auto_tag_on_upload" {if $CT_CFG.auto_tag_on_upload}checked{/if}>
            <span>{'Auto-tag photos on upload'|translate}</span>
          </label>
          <label class="ct-check">
            <input type="checkbox" name="create_new_tags" {if $CT_CFG.create_new_tags}checked{/if}>
            <span>{'Create new tags if they do not exist'|translate}</span>
          </label>
          <label class="ct-check">
            <input type="checkbox" name="overwrite_tags" {if $CT_CFG.overwrite_tags}checked{/if}>
            <span class="ct-warn-text">⚠️ {'Overwrite existing tags when re-tagging'|translate}</span>
          </label>
        </div>
      </div>

      <div class="ct-card">
        <h3 class="ct-card-title">📂 {'Tag Categories'|translate}</h3>
        <p class="ct-card-desc">{'Choose which types of content Claude will detect and tag.'|translate}</p>
        <div class="ct-cat-grid">
          {foreach $CT_ALL_CATS as $key => $label}
          <label class="ct-cat-item">
            <input type="checkbox" name="cat_{$key}" {if !empty($CT_CFG.tag_categories[$key])}checked{/if}>
            <span>{$label|escape:'html'}</span>
          </label>
          {/foreach}
        </div>
      </div>

      <div class="ct-card">
        <h3 class="ct-card-title">✍️ {'Custom Instructions'|translate}</h3>
        <div class="ct-field">
          <label for="ct_prompt">{'Additional prompt instructions'|translate} <em>(optional)</em></label>
          <textarea id="ct_prompt" name="custom_prompt" rows="3" class="ct-textarea"
                    maxlength="500" placeholder="e.g. Also tag the photography style: portrait, macro, street…">{$CT_CFG.custom_prompt|escape:'html'}</textarea>
          <p class="ct-hint">{'Max 500 characters. Appended to the standard prompt.'|translate}</p>
        </div>
      </div>

      <div class="ct-card ct-save-card">
        <div class="ct-save-row">
          <button type="submit" name="submit" class="ct-btn ct-btn-primary ct-btn-save">
            💾 {'Save Settings'|translate}
          </button>
          {if $CT_MESSAGE != '' && $CT_MSG_TYPE == 'ok'}
          <span class="ct-save-ok">✅ {'Settings saved.'|translate}</span>
          {/if}
        </div>
      </div>

    </form>
  {* Standalone test-connection form — outside the settings form to avoid nesting *}
  <form method="post" action="" id="ct-test-form" style="display:none">
    <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
    <input type="hidden" name="action"    value="test_api">
    <input type="hidden" name="api_key"   id="ct-test-key" value="">
  </form>

  </div>

  {* ═══════════════════════════════════════════════════════════════════ *}
  {* TAG PHOTOS TAB                                                      *}
  {* ═══════════════════════════════════════════════════════════════════ *}
  <div id="ct-tagging" class="ct-panel">

    <div class="ct-card">
      <h3 class="ct-card-title">🖼️ {'Tag a Single Photo'|translate}</h3>
      <p class="ct-card-desc">{'Enter a photo ID to analyse it with Claude and apply tags immediately. Find the ID in the URL when editing a photo.'|translate}</p>
      <form method="post" action="">
        <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
        <input type="hidden" name="action"    value="tag_single">
        <div class="ct-row">
          <input type="number" name="image_id" min="1" class="ct-input" placeholder="42" style="max-width:160px">
          <button type="submit" class="ct-btn ct-btn-primary">{'Tag It'|translate} →</button>
        </div>
      </form>
    </div>

    <div class="ct-card">
      <h3 class="ct-card-title">🔍 {'Image Size Diagnostic'|translate}</h3>
      <p class="ct-card-desc">{'Check the original file size and whether a resized temp file will be created before sending to the API (5 MB limit).'|translate}</p>
      <form method="post" action="">
        <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
        <input type="hidden" name="action"    value="tag_single_debug">
        <div class="ct-row">
          <input type="number" name="image_id_debug" min="1" class="ct-input" placeholder="42" style="max-width:160px">
          <button type="submit" class="ct-btn ct-btn-secondary">🔍 {'Check Size'|translate}</button>
        </div>
      </form>
    </div>

    <div class="ct-card">
      <h3 class="ct-card-title">⚡ {'Batch Tagging'|translate}</h3>
      <p class="ct-card-desc">{'Tag multiple photos. Leave IDs empty to process the most recent photos up to the limit.'|translate}</p>
      <form method="post" action="">
        <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
        <input type="hidden" name="action"    value="tag_batch">
        <div class="ct-field">
          <label for="ct_ids">{'Photo IDs'|translate} <em>(optional)</em></label>
          <textarea id="ct_ids" name="image_ids" rows="3" class="ct-textarea"
                    placeholder="12, 34, 56  — or leave empty to use the limit below"></textarea>
        </div>
        <div class="ct-row" style="align-items:center;gap:10px;margin-bottom:12px">
          <label style="white-space:nowrap;margin:0">{'Limit'|translate}</label>
          <input type="number" name="batch_limit" min="1" max="200" value="20" class="ct-input" style="width:70px">
          <span class="ct-hint" style="margin:0">{'photos max'|translate}</span>
        </div>
        <div class="ct-warn-box">⚠️ {'Batch tagging uses Anthropic API credits per photo. A 300ms delay is applied between requests.'|translate}</div>
        <button type="submit" class="ct-btn ct-btn-primary">🚀 {'Start Batch Tagging'|translate}</button>
      </form>
    </div>
  </div>

  {* ═══════════════════════════════════════════════════════════════════ *}
  {* RECENT PHOTOS TAB                                                   *}
  {* ═══════════════════════════════════════════════════════════════════ *}
  <div id="ct-gallery" class="ct-panel">
    <div class="ct-card">
      <h3 class="ct-card-title">🖼️ {'Recent Photos'|translate}</h3>
      <p class="ct-card-desc">{'Last 10 photos with their current tags.'|translate}</p>
      <div class="ct-table-wrap">
        <table class="ct-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>{'Filename'|translate}</th>
              <th>{'Title'|translate}</th>
              <th>{'Tags'|translate}</th>
              <th>{'Action'|translate}</th>
            </tr>
          </thead>
          <tbody>
          {if empty($CT_RECENT)}
            <tr><td colspan="5" class="ct-empty">{'No photos found.'|translate}</td></tr>
          {else}
            {foreach $CT_RECENT as $img}
            <tr>
              <td class="ct-mono">{$img.id|intval}</td>
              <td class="ct-mono ct-sm">{$img.filename|escape:'html'}</td>
              <td>{$img.name|escape:'html'}</td>
              <td>
                {if $img.tags_array}
                  <div class="ct-tags">
                  {foreach $img.tags_array as $tag}
                    <span class="ct-chip">{$tag|escape:'html'}</span>
                  {/foreach}
                  </div>
                {else}
                  <em class="ct-none">{'No tags yet'|translate}</em>
                {/if}
              </td>
              <td>
                <form method="post" action="" style="margin:0">
                  <input type="hidden" name="pwg_token" value="{$CT_TOKEN}">
                  <input type="hidden" name="action"    value="tag_single">
                  <input type="hidden" name="image_id"  value="{$img.id|intval}">
                  <button type="submit" class="ct-btn ct-btn-sm ct-btn-secondary">{'Tag'|translate}</button>
                </form>
              </td>
            </tr>
            {/foreach}
          {/if}
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
(function(){
  // Tab switching
  document.querySelectorAll('.ct-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.ct-tab').forEach(function(b){ b.classList.remove('ct-tab-active'); });
      document.querySelectorAll('.ct-panel').forEach(function(p){ p.classList.remove('ct-panel-active'); });
      btn.classList.add('ct-tab-active');
      var panel = document.getElementById(btn.dataset.tab);
      if(panel) panel.classList.add('ct-panel-active');
    });
  });
  // Show/hide API key
  var k = document.getElementById('ct_api_key'), s = document.getElementById('ct-show-key');
  if(k && s){
    s.addEventListener('click', function(){ k.type = k.type==='password' ? 'text' : 'password'; s.textContent = k.type==='password' ? 'Show' : 'Hide'; });
  }

  // Test Connection button — copies current key value into the standalone form and submits it
  var testBtn  = document.getElementById('ct-test-btn');
  var testForm = document.getElementById('ct-test-form');
  var testKey  = document.getElementById('ct-test-key');
  if (testBtn && testForm && testKey && k) {
    testBtn.addEventListener('click', function() {
      testKey.value = k.value;
      testForm.submit();
    });
  }
})();
</script>
