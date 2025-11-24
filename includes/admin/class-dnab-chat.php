<?php
if (!defined('ABSPATH')) { exit; }

class DNAB_Chat {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function menu(){
        add_management_page(
            'DNI Chat',
            'DNI Chat',
            'edit_posts',
            'dnab-chat',
            [__CLASS__, 'render']
        );
    }

    public static function enqueue($hook){
        if ($hook !== 'tools_page_dnab-chat') return;
        wp_enqueue_script('dnab-chat', DNAB_URL . 'assets/dnab-chat.js', array(), DNAB_VERSION, true);
        $lights = array(
            'dni'       => class_exists('DNI_MR') && class_exists('DNI_CID'),
            'abilities' => function_exists('register_ability') || function_exists('wp_register_ability'),
            'ai_client' => class_exists('WordPress\\AI_Client\\AI_Client'),
        );
        wp_localize_script('dnab-chat','DNAB', array(
            'restBase' => esc_url_raw( rest_url('dni-abilities/v1/') ),
            'nonce'    => wp_create_nonce('wp_rest'),
            'lights'   => $lights,
            'defaults' => array('maxLen'=>70),
        ));
        wp_add_inline_style('wp-admin', '.dnab-chat{border:1px solid #e5e7eb;background:#fff;border-radius:8px;max-height:420px;overflow:auto;padding:10px}.dnab-row{margin:8px 0}.dnab-chip{display:inline-block;margin-right:6px;margin-top:6px;padding:4px 8px;border-radius:9999px;border:1px solid #e5e7eb;background:#f8fafc;cursor:pointer}.dnab-chip:hover{background:#eef2ff} .dnab-status{margin:8px 0;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;border-radius:6px;display:inline-block} .dnab-input{width:200px} .dnab-log{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap} .ok{color:#166534} .err{color:#991b1b} .step{color:#1d4ed8}');
    }

    public static function render(){
        if (!current_user_can('edit_posts')) return;
        ?>
        <div class="wrap">
          <h1>DNI Chat</h1>
          <div class="dnab-status">
            <strong>Status:</strong>
            <span id="dnab-light-dni">DNI</span>
            <span id="dnab-light-abilities" style="margin-left:10px;">Abilities API</span>
            <span id="dnab-light-aiclient" style="margin-left:10px;">AI Client</span>
          </div>

          <div class="dnab-row">
            <label for="dnab-chat-post">Post ID:&nbsp;</label>
            <input id="dnab-chat-post" class="dnab-input" type="number" min="1" placeholder="e.g. 123"/>
            <label for="dnab-chat-index" style="margin-left:10px;">Index:&nbsp;</label>
            <input id="dnab-chat-index" class="dnab-input" type="number" min="0" value="0"/>
            <label for="dnab-chat-max" style="margin-left:10px;">Max title len:&nbsp;</label>
            <input id="dnab-chat-max" class="dnab-input" type="number" min="10" max="120" value="70"/>
          </div>

          <div class="dnab-row">
            <span class="dnab-chip" id="dnab-chip-read">Read MR</span>
            <span class="dnab-chip" id="dnab-chip-summarize">Agentic Summarize</span>
            <span class="dnab-chip" id="dnab-chip-title">Generate Safe Title</span>
            <span class="dnab-chip" id="dnab-chip-insert">Insert H2 @ index</span>
          </div>

          <div class="dnab-row">
            <div id="dnab-chat" class="dnab-chat dnab-log" aria-live="polite"></div>
          </div>

          <div class="dnab-row">
            <input id="dnab-chat-input" type="text" class="regular-text" placeholder="Type a note (UI is action-first; typing optional)…"/>
            <button class="button" id="dnab-chat-send">Send</button>
            <button class="button" id="dnab-chat-clear">Clear</button>
          </div>
        </div>
        <?php
    }
}

