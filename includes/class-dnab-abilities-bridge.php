<?php
if (!defined('ABSPATH')) { exit; }

class DNAB_Abilities_Bridge {
    public static function maybe_init(){
        // Heuristic detection of Abilities API
        $has_register_fn = function_exists('register_ability') || function_exists('wp_register_ability');
        if (!$has_register_fn) return;
        add_action('abilities_register', [__CLASS__, 'register_abilities']);
    }

    private static function register_ability($handle, array $def){
        if (function_exists('register_ability')){
            return register_ability($handle, $def);
        }
        if (function_exists('wp_register_ability')){
            return wp_register_ability($handle, $def);
        }
        return null;
    }

    public static function register_abilities(){
        // READ MR
        self::register_ability('dni/get-post-mr', array(
            'description' => 'Get the Machine Representation (MR) of a post with CID and links.',
            'category'    => 'content.read',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id'],
                'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1)),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                if (!$id) return false;
                $allow = current_user_can('edit_post', $id);
                return apply_filters('dni_can_read_mr', $allow, $id, null);
            },
            'callback'    => function($input){
                $id = (int)$input['post_id'];
                $mr = DNI_MR::build($id);
                if (!$mr) return new WP_Error('not_found', 'Post not found', array('status'=>404));
                $cid = get_post_meta($id, '_dni_cid', true);
                if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
                $mr['cid'] = $cid;
                $resp = array('mr'=>$mr,'meta'=>array('etag'=>$cid,'last_modified'=>$mr['modified'] ?? null));
                return $resp;
            },
        ));

        // READ MD
        self::register_ability('dni/get-post-md', array(
            'description' => 'Get the Markdown MR representation of a post.',
            'category'    => 'content.read',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id'],
                'properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1)),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                if (!$id) return false;
                $allow = current_user_can('edit_post', $id);
                return apply_filters('dni_can_read_mr', $allow, $id, null);
            },
            'callback'    => function($input){
                $id = (int)$input['post_id'];
                $mr = DNI_MR::build($id);
                if (!$mr) return new WP_Error('not_found', 'Post not found', array('status'=>404));
                // Render Markdown using the same mapping as the REST endpoint
                $md = self::to_markdown($mr);
                $md = apply_filters('dni_markdown', $md, $mr, null);
                $etag = 'sha256-'.hash('sha256', $md);
                return array('markdown'=>$md,'meta'=>array('etag'=>$etag,'last_modified'=>$mr['modified'] ?? null));
            },
        ));

        // CATALOG
        self::register_ability('dni/get-catalog', array(
            'description' => 'Get a lightweight index of posts with CIDs for incremental sync.',
            'category'    => 'catalog',
            'input_schema'=> array(
                'type'=>'object',
                'properties'=>array(
                    'since'=>array('type'=>'string'),
                    'status'=>array('type'=>'string','enum'=>array('draft','publish','any')),
                    'types'=>array('type'=>'array','items'=>array('type'=>'string')),
                ),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function(){ return current_user_can('edit_posts'); },
            'callback'    => function($input){
                $since  = isset($input['since']) ? (string)$input['since'] : '';
                $status = isset($input['status']) ? (string)$input['status'] : '';
                $types  = isset($input['types']) && is_array($input['types']) ? $input['types'] : array('post','page');
                $post_status = in_array($status, array('draft','publish','any'), true) ? $status : 'any';
                $args = array(
                    'post_type' => $types,
                    'post_status' => $post_status,
                    'posts_per_page' => -1,
                    'orderby' => 'modified',
                    'order' => 'DESC',
                    'fields' => 'ids',
                );
                $args = apply_filters('dni_catalog_args', $args, null);
                if ($since){ $args['date_query'] = array(array('column'=>'post_modified_gmt','after'=>$since)); }
                $ids = get_posts($args);
                $items = array();
                foreach ((array)$ids as $id){
                    if (!current_user_can('edit_post', $id)) continue;
                    $mr = DNI_MR::build((int)$id);
                    if (!$mr) continue;
                    $cid = get_post_meta($id, '_dni_cid', true);
                    if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
                    $items[] = array(
                        'rid'=>(int)$id,
                        'cid'=>$cid,
                        'modified'=>$mr['modified'],
                        'status'=>$mr['status'],
                        'title'=>$mr['title'],
                    );
                }
                return array('count'=>count($items),'items'=>$items);
            },
        ));

        // SAFE WRITE (insert blocks)
        self::register_ability('dni/insert-blocks', array(
            'description' => 'Safely insert blocks with optional optimistic locking via If-Match.',
            'category'    => 'content.write',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id','insert'],
                'properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'insert'=>array('type'=>'string','enum'=>array('append','prepend','index')),
                    'index'=>array('type'=>'integer','minimum'=>0),
                    'block'=>array('type'=>'object'),
                    'blocks'=>array('type'=>'array','items'=>array('type'=>'object')),
                    'if_match'=>array('type'=>'string'),
                ),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                return $id && current_user_can('edit_post', $id);
            },
            'callback'    => function($input){
                $id = (int)$input['post_id'];
                $payload = array(
                    'insert' => (string)($input['insert'] ?? 'append'),
                );
                if (isset($input['index'])) $payload['index'] = max(0, (int)$input['index']);
                if (!empty($input['blocks']) && is_array($input['blocks'])){ $payload['blocks'] = $input['blocks']; }
                elseif (!empty($input['block']) && is_array($input['block'])){ $payload['block'] = $input['block']; }
                $req = new WP_REST_Request('POST', '/dual-native/v1/posts/'.$id.'/blocks');
                $req->set_url_params(array('id'=>$id));
                $req->set_body(wp_json_encode($payload));
                $req->set_header('Content-Type','application/json');
                if (!empty($input['if_match'])){ $req->set_header('if-match', (string)$input['if_match']); }
                $resp = DNI_REST::post_insert_block($req);
                if ($resp instanceof WP_REST_Response){
                    $data = $resp->get_data();
                    $headers = $resp->get_headers();
                    return array(
                        'mr'=>$data,
                        'meta'=>array(
                            'etag'=>$data['cid'] ?? ($headers['ETag'] ?? null),
                            'inserted_at'=>$headers['X-DNI-Inserted-At'] ?? null,
                            'counts'=>array(
                                'before'=> isset($headers['X-DNI-Top-Level-Count-Before']) ? (int)$headers['X-DNI-Top-Level-Count-Before'] : null,
                                'after' => isset($headers['X-DNI-Top-Level-Count']) ? (int)$headers['X-DNI-Top-Level-Count'] : null,
                            ),
                        ),
                    );
                }
                if ($resp instanceof WP_Error) return $resp;
                return new WP_Error('unknown_error', 'Unexpected response from insert-blocks');
            },
        ));

        // AI SUGGEST via AI Client SDK (fallback to DNI heuristic)
        self::register_ability('dni/ai-suggest', array(
            'description' => 'Suggest a summary and tags for a post using the WP AI Client SDK when available.',
            'category'    => 'ai.summarize',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id'],
                'properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'temperature'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
                    'model_pref'=>array('type'=>'array','items'=>array('type'=>'string')),
                ),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                $allow = $id && current_user_can('edit_post', $id);
                return apply_filters('dni_can_read_mr', $allow, $id, null);
            },
            'callback'    => function($input){
                $id = (int)$input['post_id'];
                $mr = DNI_MR::build($id);
                if (!$mr) return new WP_Error('not_found', 'Post not found', array('status'=>404));
                $temperature = isset($input['temperature']) ? (float)$input['temperature'] : 0.2;
                $pref = isset($input['model_pref']) && is_array($input['model_pref']) ? $input['model_pref'] : null;
                if (class_exists('WordPress\AI_Client\AI_Client')){
                    try {
                        $title = (string)($mr['title'] ?? '');
                        $text  = (string)($mr['core_content_text'] ?? '');
                        $headings = array(); if (is_array($mr['blocks'])){ foreach ($mr['blocks'] as $b){ if (($b['type']??'')==='core/heading' && !empty($b['content'])) $headings[] = $b['content']; } }
                        $h = empty($headings) ? '' : ("\nHeadings:\n- ".implode("\n- ", array_map('strval', $headings)));
                        $snippet = function_exists('mb_substr') ? mb_substr($text, 0, 6000) : substr($text, 0, 6000);
                        $prompt = "Title: $title\n\nContent (truncated):\n$snippet$h\n\nTask: Return compact JSON with keys: summary (<= 120 words), tags (array up to 5 lowercase tags). Output only JSON.";
                        $client = 'WordPress\\AI_Client\\AI_Client';
                        $p = $client::prompt($prompt)->using_temperature($temperature);
                        if ($pref){ $p = $p->using_model_preference(...$pref); }
                        if (method_exists($p, 'is_supported_for_text_generation') && !$p->is_supported_for_text_generation()){
                            throw new Exception('text_generation_not_supported');
                        }
                        $out = $p->generate_text();
                        $parsed = json_decode((string)$out, true);
                        if (is_array($parsed) && isset($parsed['summary'])){
                            return array(
                                'summary'=>(string)$parsed['summary'],
                                'tags'=> isset($parsed['tags']) && is_array($parsed['tags']) ? array_values($parsed['tags']) : array(),
                                'provider'=>'wp-ai-client'
                            );
                        }
                    } catch (Throwable $e) {
                        // fallthrough to heuristic
                    }
                }
                // Heuristic fallback
                $words = preg_split('/\s+/', trim((string)($mr['core_content_text'] ?? '')));
                $summary = implode(' ', array_slice($words, 0, 120));
                $stop = array('about','above','after','again','being','their','there','these','those','which','where','while','with','your','from','that','this','have','will','would','could','should','because','through','between','among','into','other','than');
                $freq = array(); foreach ($words as $w){ $w = strtolower(preg_replace('/[^a-z0-9]/i','',$w)); if (strlen($w) < 5) continue; if (in_array($w,$stop,true)) continue; $freq[$w] = ($freq[$w]??0)+1; }
                arsort($freq); $tags = array_slice(array_keys($freq), 0, 5);
                return array('summary'=>$summary,'tags'=>$tags,'provider'=>'heuristic');
            },
        ));

        // SUPER ABILITY: read → summarize → append
        self::register_ability('dni/agentic-summarize', array(
            'description' => 'Read MR, generate a summary with AI Client (fallback heuristic), append to post safely.',
            'category'    => 'ai.compose',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id'],
                'properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'heading'=>array('type'=>'string','default'=>'Summary'),
                    'temperature'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
                    'model_pref'=>array('type'=>'array','items'=>array('type'=>'string')),
                    'if_match'=>array('type'=>'string'),
                ),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                return $id && current_user_can('edit_post', $id);
            },
            'callback'    => function($input){
                // 1) Read MR
                $read = call_user_func_array([__CLASS__,'register_abilities_read_helper'], array((int)$input['post_id']));
                if (is_wp_error($read)) return $read;
                $mr = $read['mr']; $cid = $read['mr']['cid'];
                $heading = isset($input['heading']) ? (string)$input['heading'] : 'Summary';
                // 2) Suggest summary
                $suggest = call_user_func_array([__CLASS__,'suggest_helper'], array((int)$input['post_id'], $input));
                if (is_wp_error($suggest)) return $suggest;
                $summary = (string)($suggest['summary'] ?? '');
                if ($summary === '') return new WP_Error('empty_summary','No summary produced');
                // 3) Append blocks (If-Match default to current cid if not provided)
                $blocks = array(array('type'=>'core/heading','level'=>2,'content'=>$heading), array('type'=>'core/paragraph','content'=>$summary));
                $write_input = array('post_id'=>(int)$input['post_id'],'insert'=>'append','blocks'=>$blocks,'if_match'=> (string)($input['if_match'] ?? $cid));
                $insert = call_user_func_array([__CLASS__,'insert_helper'], array($write_input));
                return array('mr'=>$insert['mr'] ?? null,'summary'=>$summary,'provider'=>$suggest['provider'] ?? null,'meta'=>$insert['meta'] ?? array());
            },
        ));

        // GENERATE SAFE TITLE
        self::register_ability('dni/generate-title', array(
            'description' => 'Generate and safely update post title using MR context and If-Match.',
            'category'    => 'content.write',
            'input_schema'=> array(
                'type'=>'object',
                'required'=>['post_id'],
                'properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'if_match'=>array('type'=>'string'),
                    'temperature'=>array('type'=>'number','minimum'=>0,'maximum'=>1),
                    'model_pref'=>array('type'=>'array','items'=>array('type'=>'string')),
                    'max_len'=>array('type'=>'integer','minimum'=>10,'maximum'=>120),
                ),
            ),
            'output_schema'=> array('type'=>'object'),
            'permission_callback' => function($input){
                $id = (int)($input['post_id'] ?? 0);
                return $id && current_user_can('edit_post', $id);
            },
            'callback'    => function($input){
                $id = (int)$input['post_id'];
                $ifm = isset($input['if_match']) ? (string)$input['if_match'] : '';
                $temp = isset($input['temperature']) ? (float)$input['temperature'] : 0.3;
                $pref = isset($input['model_pref']) && is_array($input['model_pref']) ? $input['model_pref'] : null;
                $max  = isset($input['max_len']) ? max(10, min(120, (int)$input['max_len'])) : 70;
                return self::generate_title_helper($id, $ifm, $temp, $pref, $max);
            },
        ));
    }

    // Helper to reuse in super-ability (no duplication of defs)
    public static function register_abilities_read_helper(int $post_id){
        $mr = DNI_MR::build($post_id);
        if (!$mr) return new WP_Error('not_found','Post not found',array('status'=>404));
        $cid = get_post_meta($post_id, '_dni_cid', true);
        if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($post_id, '_dni_cid', $cid); }
        $mr['cid'] = $cid;
        return array('mr'=>$mr,'meta'=>array('etag'=>$cid,'last_modified'=>$mr['modified'] ?? null));
    }

    public static function suggest_helper(int $post_id, array $input){
        // Reuse the ai-suggest callback logic
        $cb = null;
        if (function_exists('register_ability')){
            // we cannot fetch callbacks from Abilities registry portably; duplicate minimal logic
        }
        // Call inline logic from above (class exists check)
        $mr = DNI_MR::build($post_id);
        if (!$mr) return new WP_Error('not_found','Post not found',array('status'=>404));
        $temperature = isset($input['temperature']) ? (float)$input['temperature'] : 0.2;
        $pref = isset($input['model_pref']) && is_array($input['model_pref']) ? $input['model_pref'] : null;
        if (class_exists('WordPress\AI_Client\AI_Client')){
            try {
                $title = (string)($mr['title'] ?? '');
                $text  = (string)($mr['core_content_text'] ?? '');
                $headings = array(); if (is_array($mr['blocks'])){ foreach ($mr['blocks'] as $b){ if (($b['type']??'')==='core/heading' && !empty($b['content'])) $headings[] = $b['content']; } }
                $h = empty($headings) ? '' : ("\nHeadings:\n- ".implode("\n- ", array_map('strval', $headings)));
                $snippet = function_exists('mb_substr') ? mb_substr($text, 0, 6000) : substr($text, 0, 6000);
                $prompt = "Title: $title\n\nContent (truncated):\n$snippet$h\n\nTask: Return compact JSON with keys: summary (<= 120 words), tags (array up to 5 lowercase tags). Output only JSON.";
                $client = 'WordPress\\AI_Client\\AI_Client';
                $p = $client::prompt($prompt)->using_temperature($temperature);
                if ($pref){ $p = $p->using_model_preference(...$pref); }
                if (method_exists($p, 'is_supported_for_text_generation') && !$p->is_supported_for_text_generation()){
                    throw new Exception('text_generation_not_supported');
                }
                $out = $p->generate_text();
                $parsed = json_decode((string)$out, true);
                if (is_array($parsed) && isset($parsed['summary'])){
                    return array(
                        'summary'=>(string)$parsed['summary'],
                        'tags'=> isset($parsed['tags']) && is_array($parsed['tags']) ? array_values($parsed['tags']) : array(),
                        'provider'=>'wp-ai-client'
                    );
                }
            } catch (Throwable $e) {}
        }
        $words = preg_split('/\s+/', trim((string)($mr['core_content_text'] ?? '')));
        $summary = implode(' ', array_slice($words, 0, 120));
        $stop = array('about','above','after','again','being','their','there','these','those','which','where','while','with','your','from','that','this','have','will','would','could','should','because','through','between','among','into','other','than');
        $freq = array(); foreach ($words as $w){ $w = strtolower(preg_replace('/[^a-z0-9]/i','',$w)); if (strlen($w) < 5) continue; if (in_array($w,$stop,true)) continue; $freq[$w] = ($freq[$w]??0)+1; }
        arsort($freq); $tags = array_slice(array_keys($freq), 0, 5);
        return array('summary'=>$summary,'tags'=>$tags,'provider'=>'heuristic');
    }

    public static function insert_helper(array $input){
        $id = (int)$input['post_id'];
        $payload = array('insert'=>(string)($input['insert'] ?? 'append'));
        if (isset($input['index'])) $payload['index'] = max(0, (int)$input['index']);
        if (!empty($input['blocks']) && is_array($input['blocks'])){ $payload['blocks'] = $input['blocks']; }
        elseif (!empty($input['block']) && is_array($input['block'])){ $payload['block'] = $input['block']; }
        $req = new WP_REST_Request('POST', '/dual-native/v1/posts/'.$id.'/blocks');
        $req->set_url_params(array('id'=>$id));
        $req->set_body(wp_json_encode($payload));
        $req->set_header('Content-Type','application/json');
        if (!empty($input['if_match'])){ $req->set_header('if-match', (string)$input['if_match']); }
        $resp = DNI_REST::post_insert_block($req);
        if ($resp instanceof WP_REST_Response){
            $data = $resp->get_data();
            $headers = $resp->get_headers();
            return array(
                'mr'=>$data,
                'meta'=>array(
                    'etag'=>$data['cid'] ?? ($headers['ETag'] ?? null),
                    'inserted_at'=>$headers['X-DNI-Inserted-At'] ?? null,
                    'counts'=>array(
                        'before'=> isset($headers['X-DNI-Top-Level-Count-Before']) ? (int)$headers['X-DNI-Top-Level-Count-Before'] : null,
                        'after' => isset($headers['X-DNI-Top-Level-Count']) ? (int)$headers['X-DNI-Top-Level-Count'] : null,
                    ),
                ),
            );
        }
        if ($resp instanceof WP_Error) return $resp;
        return new WP_Error('unknown_error', 'Unexpected response from insert-blocks');
    }

    public static function generate_title_helper(int $post_id, string $if_match = '', float $temperature = 0.3, ?array $model_pref = null, int $max_len = 70){
        $mr = DNI_MR::build($post_id);
        if (!$mr) return new WP_Error('not_found','Post not found',array('status'=>404));
        $current_cid = get_post_meta($post_id, '_dni_cid', true);
        if (!$current_cid){ $current_cid = DNI_CID::compute($mr); update_post_meta($post_id, '_dni_cid', $current_cid); }
        if ($if_match !== ''){
            $tok = trim($if_match);
            if (stripos($tok, 'W/') === 0) { $tok = trim(substr($tok, 2)); }
            if (strlen($tok) >= 2 && $tok[0] === '"' && substr($tok, -1) === '"') { $tok = substr($tok, 1, -1); }
            if ($tok !== $current_cid){
                $e = new WP_Error('precondition_failed','If-Match did not match current CID');
                $e->add_data(array('status'=>412,'etag'=>'"'.$current_cid.'"'));
                return $e;
            }
        }
        // THINK via AI Client (fallback heuristic)
        $core = (string)($mr['core_content_text'] ?? '');
        $core = trim($core);
        $new_title = '';
        $provider = 'heuristic';
        if (class_exists('WordPress\AI_Client\AI_Client')){
            try{
                $snippet = function_exists('mb_substr') ? mb_substr($core, 0, 600) : substr($core, 0, 600);
                $prompt = "Generate a short, catchy, human-friendly post title (<= 70 chars) for the following content.\nRespond with plain text only (no quotes).\n\nContent:\n".$snippet;
                $client = 'WordPress\\AI_Client\\AI_Client';
                $p = $client::prompt($prompt)->using_temperature($temperature);
                if ($model_pref){ $p = $p->using_model_preference(...$model_pref); }
                if (method_exists($p,'is_supported_for_text_generation') && !$p->is_supported_for_text_generation()){
                    throw new Exception('unsupported');
                }
                $out = (string)$p->generate_text();
                $new_title = trim($out);
                $provider = 'wp-ai-client';
            }catch(Throwable $e){ $new_title = ''; }
        }
        if ($new_title === ''){
            // Heuristic: prefer first heading; else sentence fragment
            $heading = '';
            if (is_array($mr['blocks'])){
                foreach ($mr['blocks'] as $b){ if (($b['type']??'')==='core/heading' && !empty($b['content'])){ $heading = (string)$b['content']; break; } }
            }
            $candidate = $heading !== '' ? $heading : $core;
            $candidate = preg_replace('/\s+/u',' ', trim((string)$candidate));
            $new_title = function_exists('mb_substr') ? mb_substr($candidate, 0, $max_len) : substr($candidate, 0, $max_len);
        }
        // Clean excessive quotes/newlines
        $new_title = trim($new_title, "\n\r\t \"'«»“”‘’");
        if (function_exists('mb_substr')){ $new_title = mb_substr($new_title, 0, $max_len); } else { $new_title = substr($new_title, 0, $max_len); }
        if ($new_title === ''){ return new WP_Error('empty_title','No title generated', array('status'=>422)); }

        $old_title = (string)($mr['title'] ?? '');
        // No-op: skip write if unchanged
        if (trim($old_title) === $new_title){
            return array(
                'old_title' => $old_title,
                'new_title' => $new_title,
                'mr'        => $mr + array('cid'=>$current_cid),
                'meta'      => array('etag'=>$current_cid,'previous_etag'=>$current_cid),
                'provider'  => $provider,
                'no_change' => true,
            );
        }
        $u = wp_update_post(array('ID'=>$post_id, 'post_title'=>$new_title), true);
        if (is_wp_error($u)) return new WP_Error('update_failed', $u->get_error_message(), array('status'=>500));

        // Rebuild MR and new CID
        delete_post_meta($post_id, '_dni_cid');
        $new_mr = DNI_MR::build($post_id);
        $new_cid = DNI_CID::compute($new_mr); update_post_meta($post_id, '_dni_cid', $new_cid);

        return array(
            'old_title' => $old_title,
            'new_title' => $new_title,
            'mr'        => $new_mr + array('cid'=>$new_cid),
            'meta'      => array('etag'=>$new_cid,'previous_etag'=>$current_cid),
            'provider'  => $provider,
        );
    }

    private static function to_markdown(array $mr): string {
        $out = '';
        if (!empty($mr['title'])){ $out .= '# ' . $mr['title'] . "\n\n"; }
        $blocks = isset($mr['blocks']) && is_array($mr['blocks']) ? $mr['blocks'] : array();
        foreach ($blocks as $blk){
            $type = isset($blk['type']) ? $blk['type'] : '';
            if ($type === 'core/heading'){
                $lvl = isset($blk['level']) ? max(1,min(6,(int)$blk['level'])) : 2;
                $txt = isset($blk['content']) ? trim((string)$blk['content']) : '';
                if ($txt !== '') $out .= str_repeat('#', $lvl) . ' ' . $txt . "\n\n";
            } elseif ($type === 'core/paragraph' || $type === 'unknown'){
                $txt = isset($blk['content']) ? trim((string)$blk['content']) : '';
                if ($txt !== '') $out .= $txt . "\n\n";
            } elseif ($type === 'core/list'){
                $items = isset($blk['items']) && is_array($blk['items']) ? $blk['items'] : array();
                $ordered = !empty($blk['ordered']);
                foreach ($items as $idx=>$it){ $out .= ($ordered ? (($idx+1).'. ') : '- ') . $it . "\n"; }
                if (!empty($items)) $out .= "\n";
            } elseif ($type === 'core/image'){
                $alt = isset($blk['altText']) ? (string)$blk['altText'] : '';
                $url = isset($blk['url']) ? (string)$blk['url'] : '';
                if ($url !== '') $out .= '!['.$alt.']('.$url.')' . "\n\n";
            } elseif ($type === 'core/code'){
                $txt = isset($blk['content']) ? (string)$blk['content'] : '';
                if ($txt !== '') $out .= "```\n" . $txt . "\n```\n\n";
            } elseif ($type === 'core/quote'){
                $txt = isset($blk['content']) ? (string)$blk['content'] : '';
                if ($txt !== ''){ foreach (preg_split('/\r\n|\r|\n/', $txt) as $ln){ $out .= '> '.$ln."\n"; } $out .= "\n"; }
            }
        }
        return rtrim($out) . "\n";
    }
}

