<?php
if (!defined('ABSPATH')) { exit; }

class DNAB_Admin {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function menu(){
        add_management_page(
            'DNI Agent Console',
            'DNI Agent Console',
            'edit_posts',
            'dnab-agent-console',
            [__CLASS__, 'render']
        );
    }

    public static function enqueue($hook){
        if ($hook !== 'tools_page_dnab-agent-console') return;
        wp_enqueue_script('dnab-admin', DNAB_URL . 'assets/dnab-admin.js', array(), DNAB_VERSION, true);
        $lights = array(
            'dni'       => class_exists('DNI_MR') && class_exists('DNI_CID'),
            'abilities' => function_exists('register_ability') || function_exists('wp_register_ability'),
            'ai_client' => class_exists('WordPress\\AI_Client\\AI_Client'),
        );
        wp_localize_script('dnab-admin','DNAB', array(
            'restBase' => esc_url_raw( rest_url('dni-abilities/v1/') ),
            'nonce'    => wp_create_nonce('wp_rest'),
            'lights'   => $lights,
            'defaults' => array('maxLen'=>70),
        ));
    }

    public static function render(){
        if (!current_user_can('edit_posts')) return;
        ?>
        <div class="wrap">
          <style>
          .dnab-console{background:#111;color:#e5e7eb;padding:12px;border-radius:4px;max-height:360px;overflow:auto;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace;font-size:12px;line-height:1.5}
          .dnab-console .ok{color:#22c55e}
          .dnab-console .err{color:#ef4444}
          .dnab-console .step{color:#60a5fa}
          .dnab-row{margin:8px 0}
          .dnab-actions{margin-top:12px}
          .dnab-input{width:200px}
          </style>
          <h1>DNI Agent Console</h1>
          <div class="dnab-status" style="margin:10px 0;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;border-radius:6px;display:inline-block;">
            <strong>Status:</strong>
            <span id="dnab-light-dni">DNI</span>
            <span id="dnab-light-abilities" style="margin-left:10px;">Abilities API</span>
            <span id="dnab-light-aiclient" style="margin-left:10px;">AI Client</span>
          </div>
          <p>Demonstrates the agentic summarize super-ability using the Dual-Native MR, safe writes, and the WordPress AI Client SDK.</p>
          <div class="dnab-row">
            <label for="dnab-post-id">Post ID:&nbsp;</label>
            <input id="dnab-post-id" class="dnab-input" type="number" min="1" placeholder="e.g. 123"/>
            <button id="dnab-run" class="button button-primary">Run Agentic Summarize</button>
          </div>
          <div class="dnab-row">
            <label for="dnab-index">Insert at index:&nbsp;</label>
            <input id="dnab-index" class="dnab-input" type="number" min="0" value="0" />
            <label for="dnab-heading" style="margin-left:8px;">Heading:&nbsp;</label>
            <input id="dnab-heading" class="dnab-input" type="text" value="Key Takeaways" />
            <button id="dnab-insert-index" class="button">Insert at Index</button>
          </div>
          <div class="dnab-actions">
            <button id="dnab-clear" class="button">Clear Console</button>
            <button id="dnab-title" class="button">Generate Safe Title</button>
          </div>
          <div class="dnab-row">
            <label for="dnab-maxlen">Max title length:&nbsp;</label>
            <input id="dnab-maxlen" class="dnab-input" type="number" min="10" max="120" value="70" />
            <span style="margin-left:12px;">Slug preview:&nbsp;<code id="dnab-slug">(after generation)</code></span>
          </div>
          <h2>Console</h2>
          <div id="dnab-console" class="dnab-console" aria-live="polite"></div>
        </div>
        <?php
    }
}
