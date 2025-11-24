<?php
if (!defined('ABSPATH')) { exit; }

class DNAB_REST {
    public static function init(){
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register(){
        register_rest_route('dni-abilities/v1', '/mr/(?P<id>\d+)', array(
            'methods'=>'GET',
            'callback'=>[__CLASS__,'get_mr'],
            'permission_callback'=>function($req){ $id=(int)$req['id']; $allow=current_user_can('edit_post',$id); return apply_filters('dni_can_read_mr',$allow,$id,$req); },
        ));
        register_rest_route('dni-abilities/v1', '/md/(?P<id>\d+)', array(
            'methods'=>'GET',
            'callback'=>[__CLASS__,'get_md'],
            'permission_callback'=>function($req){ $id=(int)$req['id']; $allow=current_user_can('edit_post',$id); return apply_filters('dni_can_read_mr',$allow,$id,$req); },
        ));
        register_rest_route('dni-abilities/v1', '/catalog', array(
            'methods'=>'GET',
            'callback'=>[__CLASS__,'get_catalog'],
            'permission_callback'=>function(){ return current_user_can('edit_posts'); },
        ));
        register_rest_route('dni-abilities/v1', '/insert', array(
            'methods'=>'POST',
            'callback'=>[__CLASS__,'post_insert'],
            'permission_callback'=>function($req){ $id=(int)($req->get_param('post_id')); return $id && current_user_can('edit_post',$id); },
        ));
        register_rest_route('dni-abilities/v1', '/ai/suggest/(?P<id>\d+)', array(
            'methods'=>'GET',
            'callback'=>[__CLASS__,'ai_suggest'],
            'permission_callback'=>function($req){ $id=(int)$req['id']; $allow=current_user_can('edit_post',$id); return apply_filters('dni_can_read_mr',$allow,$id,$req); },
        ));
        register_rest_route('dni-abilities/v1', '/agentic/summarize', array(
            'methods'=>'POST',
            'callback'=>[__CLASS__,'agentic_summarize'],
            'permission_callback'=>function($req){ $id=(int)$req->get_param('post_id'); return $id && current_user_can('edit_post',$id); },
        ));
        register_rest_route('dni-abilities/v1', '/title/generate', array(
            'methods'=>'POST',
            'callback'=>[__CLASS__,'generate_title'],
            'permission_callback'=>function($req){ $id=(int)$req->get_param('post_id'); return $id && current_user_can('edit_post',$id); },
        ));
    }

    public static function get_mr(WP_REST_Request $req){
        $id = (int)$req['id'];
        $mr = DNI_MR::build($id);
        if (!$mr) return new WP_REST_Response(array('error'=>'not_found'),404);
        $cid = get_post_meta($id, '_dni_cid', true);
        if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
        $mr['cid'] = $cid;

        // Conditional GET (If-None-Match)
        $inm = (string)$req->get_header('if-none-match');
        if ($inm === '' && isset($_SERVER['HTTP_IF_NONE_MATCH'])) { $inm = trim((string)$_SERVER['HTTP_IF_NONE_MATCH']); }
        $matches = false;
        if ($inm){
            foreach (explode(',', $inm) as $tok){
                $t = trim($tok);
                if (stripos($t, 'W/') === 0) { $t = trim(substr($t, 2)); }
                if (strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"') { $t = substr($t, 1, -1); }
                if ($t === $cid){ $matches = true; break; }
            }
        }
        $status = $matches ? 304 : 200;
        $body = $matches ? null : array('mr'=>$mr,'meta'=>array('etag'=>$cid,'last_modified'=>$mr['modified'] ?? null));
        $resp = new WP_REST_Response($body, $status);
        $resp->header('ETag', '"'.$cid.'"');
        if (!empty($mr['modified'])){ $resp->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT'); }
        $resp->header('Cache-Control', 'max-age=0, must-revalidate');
        return $resp;
    }

    public static function generate_title(WP_REST_Request $req){
        $post_id = (int)$req->get_param('post_id');
        $ifm = (string)($req->get_header('if-match') ?? '');
        $temperature = (float)($req->get_param('temperature') ?? 0.3);
        $pref = $req->get_param('model_pref'); $pref = is_array($pref) ? $pref : null;
        $max  = (int)($req->get_param('max_len') ?? 70);
        if ($max < 10) $max = 10; if ($max > 120) $max = 120;
        $out = DNAB_Abilities_Bridge::generate_title_helper($post_id, $ifm, $temperature, $pref, $max);
        if ($out instanceof WP_Error){
            $data = array('error'=>$out->get_error_code(),'message'=>$out->get_error_message());
            $status = (int)($out->get_error_data('status') ?? 400);
            $resp = new WP_REST_Response($data, $status);
            $etag = $out->get_error_data('etag'); if ($etag){ $resp->header('ETag', (string)$etag); }
            return $resp;
        }
        $resp = new WP_REST_Response($out, 200);
        if (!empty($out['meta']['etag'])){ $resp->header('ETag', '"'.$out['meta']['etag'].'"'); }
        return $resp;
    }

    public static function get_md(WP_REST_Request $req){
        $id = (int)$req['id'];
        $mr = DNI_MR::build($id);
        if (!$mr) return new WP_REST_Response(array('error'=>'not_found'),404);
        $md = self::to_markdown($mr);
        $md = apply_filters('dni_markdown', $md, $mr, $req);
        $etag = 'sha256-'.hash('sha256',$md);

        // Conditional GET (If-None-Match)
        $inm = (string)$req->get_header('if-none-match');
        if ($inm === '' && isset($_SERVER['HTTP_IF_NONE_MATCH'])) { $inm = trim((string)$_SERVER['HTTP_IF_NONE_MATCH']); }
        $match = false;
        if ($inm){
            foreach (explode(',', $inm) as $tok){
                $t = trim($tok);
                if (stripos($t,'W/')===0){ $t = trim(substr($t,2)); }
                if (strlen($t)>=2 && $t[0]=='"' && substr($t,-1)=='"'){ $t = substr($t,1,-1);} 
                if ($t===$etag){ $match=true; break; }
            }
        }
        $status = $match ? 304 : 200;
        $body = $match ? null : array('markdown'=>$md,'meta'=>array('etag'=>$etag,'last_modified'=>$mr['modified'] ?? null));
        $resp = new WP_REST_Response($body, $status);
        $resp->header('Content-Type', 'application/json; charset=UTF-8');
        $resp->header('ETag', '"'.$etag.'"');
        if (!empty($mr['modified'])){ $resp->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT'); }
        $resp->header('Cache-Control', 'max-age=0, must-revalidate');
        return $resp;
    }

    public static function get_catalog(WP_REST_Request $req){
        $since = $req->get_param('since');
        $status= $req->get_param('status');
        $types = $req->get_param('types');
        $post_status = in_array($status, array('draft','publish','any'), true) ? $status : 'any';
        $post_types = is_array($types) ? $types : array('post','page');
        $args = array(
            'post_type' => $post_types,
            'post_status' => $post_status,
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        );
        $args = apply_filters('dni_catalog_args', $args, $req);
        if ($since){ $args['date_query'] = array(array('column'=>'post_modified_gmt','after'=>$since)); }
        $ids = get_posts($args);
        $items = array();
        foreach ((array)$ids as $id){
            if (!current_user_can('edit_post', $id)) continue;
            $mr = DNI_MR::build((int)$id);
            if (!$mr) continue;
            $cid = get_post_meta($id, '_dni_cid', true);
            if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
            $items[] = array('rid'=>(int)$id,'cid'=>$cid,'modified'=>$mr['modified'],'status'=>$mr['status'],'title'=>$mr['title']);
        }
        return array('count'=>count($items),'items'=>$items);
    }

    public static function post_insert(WP_REST_Request $req){
        $id = (int)$req->get_param('post_id');
        $payload = array('insert'=>(string)$req->get_param('insert'));
        $idx = $req->get_param('index'); if ($idx !== null) $payload['index'] = max(0,(int)$idx);
        $blocks = $req->get_param('blocks'); $block = $req->get_param('block');
        if (is_array($blocks)) $payload['blocks'] = $blocks; elseif (is_array($block)) $payload['block'] = $block;
        $r = new WP_REST_Request('POST', '/dual-native/v1/posts/'.$id.'/blocks');
        $r->set_url_params(array('id'=>$id));
        $r->set_body(wp_json_encode($payload));
        $r->set_header('Content-Type','application/json');
        $ifm = $req->get_header('if-match'); if ($ifm) $r->set_header('if-match',$ifm);
        $resp = DNI_REST::post_insert_block($r);
        if ($resp instanceof WP_REST_Response){
            $data = $resp->get_data();
            $headers = $resp->get_headers();
            $meta = array(
                'etag' => isset($data['cid']) ? $data['cid'] : ($headers['ETag'] ?? null),
                'inserted_at' => $headers['X-DNI-Inserted-At'] ?? null,
                'counts' => array(
                    'before' => isset($headers['X-DNI-Top-Level-Count-Before']) ? (int)$headers['X-DNI-Top-Level-Count-Before'] : null,
                    'after'  => isset($headers['X-DNI-Top-Level-Count']) ? (int)$headers['X-DNI-Top-Level-Count'] : null,
                ),
            );
            $out = new WP_REST_Response(array('mr'=>$data,'meta'=>$meta), $resp->get_status());
            if (!empty($meta['etag'])){ $out->header('ETag', '"'.$meta['etag'].'"'); }
            // propagate telemetry headers
            foreach (['X-DNI-Top-Level-Count-Before','X-DNI-Inserted-At','X-DNI-Top-Level-Count'] as $h){ if(isset($headers[$h])) $out->header($h, $headers[$h]); }
            return $out;
        }
        return $resp;
    }

    public static function ai_suggest(WP_REST_Request $req){
        $id = (int)$req['id'];
        $temperature = (float)($req->get_param('temperature') ?? 0.2);
        $pref = $req->get_param('model_pref'); $pref = is_array($pref) ? $pref : null;
        $mr = DNI_MR::build($id); if (!$mr) return new WP_REST_Response(array('error'=>'not_found'),404);
        if (class_exists('WordPress\AI_Client\AI_Client')){
            try{
                $title=(string)($mr['title']??''); $text=(string)($mr['core_content_text']??'');
                $headings=array(); if (is_array($mr['blocks'])){ foreach ($mr['blocks'] as $b){ if(($b['type']??'')==='core/heading' && !empty($b['content'])) $headings[]=$b['content']; } }
                $h=empty($headings)?'':("\nHeadings:\n- ".implode("\n- ", array_map('strval',$headings)));
                $snippet=function_exists('mb_substr')?mb_substr($text,0,6000):substr($text,0,6000);
                $prompt = "Title: $title\n\nContent (truncated):\n$snippet$h\n\nTask: Return compact JSON with keys: summary (<= 120 words), tags (array up to 5 lowercase tags). Output only JSON.";
                $client='WordPress\\AI_Client\\AI_Client';
                $p=$client::prompt($prompt)->using_temperature($temperature);
                if ($pref){ $p=$p->using_model_preference(...$pref); }
                if (method_exists($p,'is_supported_for_text_generation') && !$p->is_supported_for_text_generation()) throw new Exception('unsupported');
                $out=$p->generate_text(); $parsed=json_decode((string)$out,true);
                if (is_array($parsed) && isset($parsed['summary'])) return array('summary'=>$parsed['summary'],'tags'=>$parsed['tags']??array(),'provider'=>'wp-ai-client');
            }catch(Throwable $e){}
        }
        // Fallback
        $words = preg_split('/\s+/', trim((string)($mr['core_content_text'] ?? '')));
        $summary = implode(' ', array_slice($words, 0, 120));
        $stop = array('about','above','after','again','being','their','there','these','those','which','where','while','with','your','from','that','this','have','will','would','could','should','because','through','between','among','into','other','than');
        $freq = array(); foreach ($words as $w){ $w = strtolower(preg_replace('/[^a-z0-9]/i','',$w)); if (strlen($w) < 5) continue; if (in_array($w,$stop,true)) continue; $freq[$w] = ($freq[$w]??0)+1; }
        arsort($freq); $tags = array_slice(array_keys($freq), 0, 5);
        return array('summary'=>$summary,'tags'=>$tags,'provider'=>'heuristic');
    }

    public static function agentic_summarize(WP_REST_Request $req){
        $post_id = (int)$req->get_param('post_id');
        $heading = (string)($req->get_param('heading') ?? 'Summary');
        $ifm = (string)($req->get_header('if-match') ?? '');
        // Suggest using the same logic as abilities bridge helper
        $s = DNAB_Abilities_Bridge::suggest_helper($post_id, array(
            'temperature' => (float)($req->get_param('temperature') ?? 0.2),
            'model_pref'  => $req->get_param('model_pref')
        ));
        if ($s instanceof WP_Error){ return new WP_REST_Response(array('error'=>$s->get_error_code()), 500); }
        if (empty($s['summary'])) return new WP_REST_Response(array('error'=>'no_summary'), 422);
        $blocks = array(array('type'=>'core/heading','level'=>2,'content'=>$heading), array('type'=>'core/paragraph','content'=>(string)$s['summary']));
        $r = new WP_REST_Request('POST', '/dni-abilities/v1/insert');
        $r->set_body_params(array('post_id'=>$post_id,'insert'=>'append','blocks'=>$blocks));
        if ($ifm){ $r->set_header('if-match',$ifm); }
        $insert = self::post_insert($r);
        if ($insert instanceof WP_REST_Response){ $data = $insert->get_data(); $code=$insert->get_status(); } else { $data = $insert; $code=200; }
        return new WP_REST_Response(array('mr'=>$data['mr'] ?? null,'summary'=>$s['summary'],'provider'=>$s['provider'] ?? null,'meta'=>$data['meta'] ?? null), $code);
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
